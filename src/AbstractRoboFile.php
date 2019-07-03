<?php

namespace DigipolisGent\Robo\Helpers;

use DigipolisGent\CommandBuilder\CommandBuilder;
use DigipolisGent\Robo\Task\Deploy\Ssh\Auth\AbstractAuth;
use DigipolisGent\Robo\Task\Deploy\Ssh\Auth\KeyFile;
use DigipolisGent\Robo\Task\General\Common\DigipolisPropertiesAwareInterface;
use Robo\Contract\ConfigAwareInterface;
use Robo\Task\Filesystem\FilesystemStack;
use Symfony\Component\Finder\Finder;

abstract class AbstractRoboFile extends \Robo\Tasks implements DigipolisPropertiesAwareInterface, ConfigAwareInterface
{
    const DEFAULT_TIMEOUT = 10;

    use \DigipolisGent\Robo\Task\Package\Commands\loadCommands;
    use \DigipolisGent\Robo\Task\General\loadTasks;
    use \DigipolisGent\Robo\Task\General\Common\DigipolisPropertiesAware;
    use \Robo\Common\ConfigAwareTrait;
    use \DigipolisGent\Robo\Task\Deploy\Commands\loadCommands;
    use \DigipolisGent\Robo\Task\Deploy\Traits\SshTrait;
    use \DigipolisGent\Robo\Task\Deploy\Traits\ScpTrait;
    use \Robo\Task\Base\loadTasks;

    /**
     * Stores the request time.
     *
     * @var int
     */
    protected $time;

    /**
     * File backup subdirs.
     *
     * @var string[]
     */
    protected $fileBackupSubDirs = [];

    /**
     * Files or directories to exclude from the backup.
     *
     * @var string[]
     */
    protected $excludeFromBackup = [];

    /**
     * Create a RoboFileBase instance.
     */
    public function __construct()
    {
        $this->time = time();
    }

    /**
     * Execute code validation for this project.
     */
    abstract public function digipolisValidateCode();

    /**
     * Build a site and push it to the servers.
     *
     * @param array $arguments
     *   Variable amount of arguments. The last argument is the path to the
     *   the private key file (ssh), the penultimate is the ssh user. All
     *   arguments before that are server IP's to deploy to.
     * @param array $opts
     *   The options for this task.
     *
     * @return \Robo\Contract\TaskInterface
     *   The deploy task.
     */
    protected function deployTask(
        array $arguments,
        $opts
    ) {
        // Define variables.
        $opts += ['force-install' => false];
        $privateKeyFile = array_pop($arguments);
        $user = array_pop($arguments);
        $servers = $arguments;
        $worker = is_null($opts['worker']) ? reset($servers) : $opts['worker'];
        $remote = $this->getRemoteSettings($servers, $user, $privateKeyFile, $opts['app']);
        $releaseDir = $remote['releasesdir'] . '/' . $remote['time'];
        $auth = new KeyFile($user, $privateKeyFile);
        $archive = $remote['time'] . '.tar.gz';
        $backupOpts = ['files' => false, 'data' => true];

        $collection = $this->collectionBuilder();

        // Build the archive to deploy.
        $collection->addTask($this->buildTask($archive));

        // Create a backup and a rollback task if a site is already installed.
        if ($remote['createbackup'] && $this->isSiteInstalled($worker, $auth, $remote) && $this->currentReleaseHasRobo($worker, $auth, $remote)) {
            // Create a backup.
            $collection->addTask($this->backupTask($worker, $auth, $remote, $backupOpts));

            // Create a rollback for this backup for when the deploy fails.
            $collection->rollback(
                $this->restoreBackupTask(
                    $worker,
                    $auth,
                    $remote,
                    $backupOpts
                )
            );
        }

        // Push the package to the servers and create the required symlinks.
        foreach ($servers as $server) {
            // Remove this release on rollback.
            $collection->rollback($this->removeFailedRelease($server, $auth, $remote, $releaseDir));

            // Push the package.
            $collection->addTask($this->pushPackageTask($server, $auth, $remote, $archive));

            // Add any tasks to execute before creating the symlinks.
            $preSymlink = $this->preSymlinkTask($server, $auth, $remote);
            if ($preSymlink) {
                $collection->addTask($preSymlink);
            }

            // Switch the current symlink to the previous release on rollback.
            $collection->rollback($this->switchPreviousTask($server, $auth, $remote));

            // Create the symlinks.
            $collection->addTask($this->symlinksTask($server, $auth, $remote));
            $postSymlink = $this->postSymlinkTask($server, $auth, $remote);
            if ($postSymlink) {
                $collection->addTask($postSymlink);
            }
        }

        // Initialize the site (update or install).
        $collection->addTask($this->initRemoteTask($worker, $auth, $remote, $opts, $opts['force-install']));

        // Clear OPcache if present.
        if (isset($remote['opcache']) && (!array_key_exists('clear', $remote['opcache']) || $remote['opcache']['clear'])) {
            foreach ($servers as $server) {
                $collection->addTask($this->clearOpCacheTask($server, $auth, $remote));
            }
        }

        // Clean release and backup dirs on the servers.
        foreach ($servers as $server) {
            $collection->completion($this->cleanDirsTask($server, $auth, $remote));
        }

        $clearCache = $this->clearCacheTask($worker, $auth, $remote);

        // Clear the site's cache if required.
        if ($clearCache) {
            $collection->completion($clearCache);
        }
        return $collection;
    }

    protected function currentReleaseHasRobo($worker, AbstractAuth $auth, $remote)
    {
        $currentProjectRoot = $this->getCurrentProjectRoot($worker, $auth, $remote);
        return $this->taskSsh($worker, $auth)
            ->remoteDirectory($currentProjectRoot, true)
            ->exec('ls vendor/bin/robo | grep robo')
            ->run()
            ->wasSuccessful();
    }

    public function getCurrentProjectRoot($worker, AbstractAuth $auth, $remote)
    {
        $fullOutput = '';
        $this->taskSsh($worker, $auth)
            ->remoteDirectory($remote['releasesdir'], true)
            ->exec('ls -1 | sort -r | head -1', function ($output) use (&$fullOutput) {
                $fullOutput .= $output;
            })
            ->run();
        return $remote['releasesdir'] . '/' . substr($fullOutput, 0, (strpos($fullOutput, "\n") ?: strlen($fullOutput)));
    }

    /**
     * Check if a site is already installed
     *
     * @param string $worker
     *   The server to install the site on.
     * @param \DigipolisGent\Robo\Task\Deploy\Ssh\Auth\AbstractAuth $auth
     *   The ssh authentication to connect to the server.
     * @param array $remote
     *   The remote settings for this server.
     *
     * @return bool
     *   Whether or not the site is installed.
     */
    abstract protected function isSiteInstalled($worker, AbstractAuth $auth, $remote);

    /**
     * Clear cache of the site.
     *
     * @param string $worker
     *   The server to install the site on.
     * @param \DigipolisGent\Robo\Task\Deploy\Ssh\Auth\AbstractAuth $auth
     *   The ssh authentication to connect to the server.
     * @param array $remote
     *   The remote settings for this server.
     *
     * @return bool|\Robo\Contract\TaskInterface
     *   The clear cache task or false if no clear cache task exists.
     */
    protected function clearCacheTask($worker, $auth, $remote)
    {
        return false;
    }

    /**
     * Switch the current release symlink to the previous release.
     *
     * @param string $releasesDir
     *   Path to the folder containing all releases.
     * @param string $currentSymlink
     *   Path to the current release symlink.
     */
    public function digipolisSwitchPrevious($releasesDir, $currentSymlink)
    {
        $finder = new Finder();
        // Get all releases.
        $releases = iterator_to_array(
            $finder
                ->directories()
                ->in($releasesDir)
                ->sortByName()
                ->depth(0)
                ->getIterator()
        );
        // Last element is the current release.
        array_pop($releases);
        if ($releases) {
            // Normalize the paths.
            $currentDir = readlink($currentSymlink);
            $releasesDir = realpath($releasesDir);
            // Get the right folder within the release dir to symlink.
            $relativeRootDir = substr($currentDir, strlen($releasesDir . '/'));
            $parts = explode('/', $relativeRootDir);
            array_shift($parts);
            $relativeWebDir = implode('/', $parts);
            $previous = end($releases)->getRealPath() . '/' . $relativeWebDir;
            return $this->taskExec('ln -s -T -f ' . $previous . ' ' . $currentSymlink)
                ->run();
        }
    }

    /**
     * @return FilesystemStack
     */
    protected function taskFilesystemStack()
    {
        return $this->task(FilesystemStack::class);
    }

    /**
     * Mirror a directory.
     *
     * @param string $dir
     *   Path of the directory to mirror.
     * @param string $destination
     *   Path of the directory where $dir should be mirrored.
     *
     * @return \Robo\Contract\TaskInterface
     *   The mirror dir task.
     */
    public function digipolisMirrorDir($dir, $destination)
    {
        if (!is_dir($dir)) {
            return;
        }
        $task = $this->taskFilesystemStack();
        $task->mkdir($destination);

        $directoryIterator = new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS);
        $recursiveIterator = new \RecursiveIteratorIterator($directoryIterator, \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($recursiveIterator as $item) {
            $destinationFile = $destination . DIRECTORY_SEPARATOR . $recursiveIterator->getSubPathName();
            if (file_exists($destinationFile)) {
                continue;
            }
            if (is_link($item)) {
                if ($item->getRealPath() !== false) {
                    $task->symlink($item->getLinkTarget(), $destinationFile);
                }
                continue;
            }
            if ($item->isDir()) {
                $task->mkdir($destinationFile);
                continue;
            }
            $task->copy($item, $destinationFile);
        }
        return $task;
    }

    /**
     * Build a site and package it.
     *
     * @param string $archivename
     *   Name of the archive to create.
     *
     * @return \Robo\Contract\TaskInterface
     *   The deploy task.
     */
    protected function buildTask($archivename = null)
    {
        $this->readProperties();
        $archive = is_null($archivename) ? $this->time . '.tar.gz' : $archivename;
        $collection = $this->collectionBuilder();
        $collection
            ->taskPackageProject($archive);
        return $collection;
    }

    /**
     * Tasks to execute after creating the symlinks.
     *
     * @param string $worker
     *   The server to install the site on.
     * @param \DigipolisGent\Robo\Task\Deploy\Ssh\Auth\AbstractAuth $auth
     *   The ssh authentication to connect to the server.
     * @param array $remote
     *   The remote settings for this server.
     *
     * @return bool|\Robo\Contract\TaskInterface
     *   The postsymlink task, false if no post symlink tasks need to run.
     */
    protected function postSymlinkTask($worker, AbstractAuth $auth, $remote)
    {
        if (isset($remote['postsymlink_filechecks']) && $remote['postsymlink_filechecks']) {
            $projectRoot = $remote['rootdir'];
            $collection = $this->collectionBuilder();
            $collection->taskSsh($worker, $auth)
                ->remoteDirectory($projectRoot, true)
                ->timeout($this->getTimeoutSetting('postsymlink_filechecks'));
            foreach ($remote['postsymlink_filechecks'] as $file) {
                // If this command fails, the collection will fail, which will
                // trigger a rollback.
                $builder = CommandBuilder::create('ls')
                    ->addArgument($file)
                    ->pipeOutputTo('grep')
                    ->addArgument($file)
                    ->onFailure(
                        CommandBuilder::create('echo')
                            ->addArgument('[ERROR] ' . $file . ' was not found.')
                            ->onFinished('exit')
                            ->addArgument('1')
                    );
                $collection->exec((string) $builder);
            }
            return $collection;
        }
        return false;
    }

    /**
     * Tasks to execute before creating the symlinks.
     *
     * @param string $worker
     *   The server to install the site on.
     * @param \DigipolisGent\Robo\Task\Deploy\Ssh\Auth\AbstractAuth $auth
     *   The ssh authentication to connect to the server.
     * @param array $remote
     *   The remote settings for this server.
     *
     * @return bool|\Robo\Contract\TaskInterface
     *   The presymlink task, false if no pre symlink tasks need to run.
     */
    protected function preSymlinkTask($worker, AbstractAuth $auth, $remote)
    {
        $projectRoot = $remote['rootdir'];
        $collection = $this->collectionBuilder();
        $collection->taskSsh($worker, $auth)
            ->remoteDirectory($projectRoot, true)
            ->timeout($this->getTimeoutSetting('presymlink_mirror_dir'));
        foreach ($remote['symlinks'] as $symlink) {
            list($target, $link) = explode(':', $symlink);
            if ($link === $remote['currentdir']) {
                continue;
            }
            // If the link we're going to create is an existing directory,
            // mirror that directory on the symlink target and then delete it
            // before creating the symlink
            $collection->exec('vendor/bin/robo digipolis:mirror-dir ' . $link . ' ' . $target);
            $collection->exec('rm -rf ' . $link);
        }
        return $collection;
    }

    /**
     * Install or update a remote site.
     *
     * @param string $worker
     *   The server to install the site on.
     * @param \DigipolisGent\Robo\Task\Deploy\Ssh\Auth\AbstractAuth $auth
     *   The ssh authentication to connect to the server.
     * @param array $remote
     *   The remote settings for this server.
     * @param array $extra
     *   Extra parameters to pass to site install.
     * @param bool $force
     *   Whether or not to force the install even when the site is present.
     *
     * @return \Robo\Contract\TaskInterface
     *   The init remote task.
     */
    protected function initRemoteTask($worker, AbstractAuth $auth, $remote, $extra = [], $force = false)
    {
        $collection = $this->collectionBuilder();
        if (!$this->isSiteInstalled($worker, $auth, $remote) || $force) {
            $this->say($force ? 'Forcing site install.' : 'Site status failed.');
            $this->say('Triggering install script.');

            $collection->addTask($this->installTask($worker, $auth, $remote, $extra, $force));
            return $collection;
        }
        $collection->addTask($this->updateTask($worker, $auth, $remote, $extra));
        return $collection;
    }

    /**
     * Executes database updates of the site in the current folder.
     *
     * Executes database updates of the site in the current folder. Sets
     * the site in maintenance mode before the update and takes in out of
     * maintenance mode after.
     *
     * @param string $worker
     *   The server to install the site on.
     * @param \DigipolisGent\Robo\Task\Deploy\Ssh\Auth\AbstractAuth $auth
     *   The ssh authentication to connect to the server.
     * @param array $remote
     *   The remote settings for this server.
     *
     * @return \Robo\Contract\TaskInterface
     *   The update task.
     */
    abstract protected function updateTask($worker, AbstractAuth $auth, $remote);

    /**
     * Install the site in the current folder.
     *
     * @param string $worker
     *   The server to install the site on.
     * @param \DigipolisGent\Robo\Task\Deploy\Ssh\Auth\AbstractAuth $auth
     *   The ssh authentication to connect to the server.
     * @param array $remote
     *   The remote settings for this server.
     * @param bool $force
     *   Whether or not to force the install even when the site is present.
     *
     * @return \Robo\Contract\TaskInterface
     *   The install task.
     */
    abstract protected function installTask($worker, AbstractAuth $auth, $remote, $extra = [], $force = false);

    /**
     * Sync the database and files between two sites.
     *
     * @param string $sourceUser
     *   SSH user to connect to the source server.
     * @param string $sourceHost
     *   IP address of the source server.
     * @param string $sourceKeyFile
     *   Private key file to use to connect to the source server.
     * @param string $destinationUser
     *   SSH user to connect to the destination server.
     * @param string $destinationHost
     *   IP address of the destination server.
     * @param string $destinationKeyFile
     *   Private key file to use to connect to the destination server.
     * @param string $sourceApp
     *   The name of the source app we're syncing. Used to determine the
     *   directory to sync.
     * @param string $destinationApp
     *   The name of the destination app we're syncing. Used to determine the
     *   directory to sync to.
     *
     * @return \Robo\Contract\TaskInterface
     *   The sync task.
     */
    protected function syncTask(
        $sourceUser,
        $sourceHost,
        $sourceKeyFile,
        $destinationUser,
        $destinationHost,
        $destinationKeyFile,
        $sourceApp = 'default',
        $destinationApp = 'default',
        $opts = ['files' => false, 'data' => false, 'rsync' => true]
    ) {
        if (!$opts['files'] && !$opts['data']) {
            $opts['files'] = true;
            $opts['data'] = true;
        }

        $opts['rsync'] = !isset($opts['rsync']) || $opts['rsync'];

        $sourceRemote = $this->getRemoteSettings(
            $sourceHost,
            $sourceUser,
            $sourceKeyFile,
            $sourceApp
        );
        $sourceAuth = new KeyFile($sourceUser, $sourceKeyFile);

        $destinationRemote = $this->getRemoteSettings(
            $destinationHost,
            $destinationUser,
            $destinationKeyFile,
            $destinationApp
        );
        $destinationAuth = new KeyFile($destinationUser, $destinationKeyFile);

        $collection = $this->collectionBuilder();

        if ($opts['files'] && $opts['rsync']) {
            $opts['files'] = false;

            $tmpKeyFile = '~/.ssh/' . uniqid('robo_', true) . '.id_rsa';

            // Generate a temporary key.
            $collection->addTask(
                $this->taskExec('ssh-keygen -q -t rsa -b 4096 -N "" -f ' . $tmpKeyFile . ' -C "robo:' . md5($tmpKeyFile) . '"')
            );

            $collection->completion(
                $this->taskExecStack()
                    ->exec('rm -f ' . $tmpKeyFile . ' ' . $tmpKeyFile . '.pub')
            );

            // Install it on the destination host.
            $collection->addTask(
                $this->taskExec('cat ' . $tmpKeyFile . '.pub | ssh ' . $destinationUser . '@' . $destinationHost . ' -o StrictHostKeyChecking=no -i ' . $destinationKeyFile . ' "mkdir -p ~/.ssh && cat >> ~/.ssh/authorized_keys"')
            );

            $collection->completion(
                $this->taskSsh($destinationHost, $destinationAuth)
                    ->exec('sed -i "/robo:' . md5($tmpKeyFile) . '/d" ~/.ssh/authorized_keys')
            );

            $collection->addTask(
                $this->taskRsync()
                    ->rawArg('--rsh "ssh -o StrictHostKeyChecking=no -i `vendor/bin/robo digipolis:realpath ' . $sourceKeyFile . '`"')
                    ->fromPath($tmpKeyFile)
                    ->toHost($sourceHost)
                    ->toUser($sourceUser)
                    ->toPath('~/.ssh')
                    ->archive()
                    ->compress()
                    ->checksum()
                    ->wholeFile()
            );

            $collection->completion(
                $this->taskSsh($sourceHost, $sourceAuth)
                    ->exec('rm -f ' . $tmpKeyFile)
            );

            $dirs = ($this->fileBackupSubDirs ? $this->fileBackupSubDirs : ['']);

            foreach ($dirs as $dir) {
                $dir .= ($dir !== '' ? '/' : '');

                $rsync = $this->taskRsync()
                    ->rawArg('--rsh "ssh -o StrictHostKeyChecking=no -i `cd -P ' . $sourceRemote['currentdir'] . '/.. && vendor/bin/robo digipolis:realpath ' . $tmpKeyFile . '`"')
                    ->fromPath($sourceRemote['filesdir'] . '/' . $dir)
                    ->toHost($destinationHost)
                    ->toUser($destinationUser)
                    ->toPath($destinationRemote['filesdir'] . '/' . $dir)
                    ->archive()
                    ->delete()
                    ->rawArg('--copy-links --keep-dirlinks')
                    ->compress()
                    ->checksum()
                    ->wholeFile();

                foreach ($this->excludeFromBackup as $exclude) {
                    $rsync->exclude($exclude);
                }

                $collection->addTask(
                    $this->taskSsh($sourceHost, $sourceAuth)
                        ->timeout($this->getTimeoutSetting('synctask_rsync'))
                        ->exec($rsync)
                );
            }
        }

        if ($opts['data'] || $opts['files']) {
            // Create a backup.
            $collection->addTask(
                $this->backupTask(
                    $sourceHost,
                    $sourceAuth,
                    $sourceRemote,
                    $opts
                )
            );
            // Sync the backup.
            $collection->addTask(
                $this->downloadBackupTask(
                    $sourceHost,
                    $sourceAuth,
                    $sourceRemote,
                    $opts
                )
            );
            // Remove the backup from the source.
            $collection->addTask(
                $this->removeBackupTask(
                    $sourceHost,
                    $sourceAuth,
                    $sourceRemote,
                    $opts
                )
            );
            // Upload the backup.
            $collection->addTask(
                $this->uploadBackupTask(
                    $destinationHost,
                    $destinationAuth,
                    $destinationRemote,
                    $opts
                )
            );
            // Restore the backup.
            $collection->addTask(
                $this->restoreBackupTask(
                    $destinationHost,
                    $destinationAuth,
                    $destinationRemote,
                    $opts
                )
            );
            // Remove the backup from the destination.
            $collection->completion(
                $this->removeBackupTask(
                    $destinationHost,
                    $destinationAuth,
                    $destinationRemote,
                    $opts
                )
            );

            // Finally remove the local backups.
            $dbBackupFile = $this->backupFileName('.sql.gz', $sourceRemote['time']);
            $filesBackupFile = $opts['files'] ? $this->backupFileName('.tar.gz', $sourceRemote['time']) : '';

            $collection->completion(
                $this->taskExecStack()
                    ->exec('rm -f ' . $dbBackupFile . ' ' . $filesBackupFile)
            );
        }

        return $collection;
    }

    /**
     * Create a backup of files (storage folder) and database.
     *
     * @param string $worker
     *   The server to install the site on.
     * @param \DigipolisGent\Robo\Task\Deploy\Ssh\Auth\AbstractAuth $auth
     *   The ssh authentication to connect to the server.
     * @param array $remote
     *   The remote settings for this server.
     *
     * @return \Robo\Contract\TaskInterface
     *   The backup task.
     */
    protected function backupTask(
        $worker,
        AbstractAuth $auth,
        $remote,
        $opts = ['files' => false, 'data' => false]
    ) {
        if (!$opts['files'] && !$opts['data']) {
            $opts['files'] = true;
            $opts['data'] = true;
        }
        $backupDir = $remote['backupsdir'] . '/' . $remote['time'];
        $currentProjectRoot = $this->getCurrentProjectRoot($worker, $auth, $remote);

        $collection = $this->collectionBuilder();
        $collection->taskSsh($worker, $auth)
            ->exec('mkdir -p ' . $backupDir);

        if ($opts['files']) {
            $filesBackupFile = $this->backupFileName('.tar.gz');
            $excludeFromBackup = '';
            foreach ($this->excludeFromBackup as $exclude) {
                $excludeFromBackup .= ' --exclude=' . $exclude;
            }
            $filesBackup = 'tar -pczhf ' . $backupDir . '/'  . $filesBackupFile
                . ' -C ' . $remote['filesdir']
                . $excludeFromBackup
                . ' ' . ($this->fileBackupSubDirs ? implode(' ', $this->fileBackupSubDirs) : '*');
            $collection->taskSsh($worker, $auth)
                ->remoteDirectory($remote['filesdir'])
                ->timeout($this->getTimeoutSetting('backup_files'))
                ->exec($filesBackup);
        }

        if ($opts['data']) {
            $dbBackupFile = $this->backupFileName('.sql');
            $dbBackup = 'vendor/bin/robo digipolis:database-backup '
                . '--destination=' . $backupDir . '/' . $dbBackupFile;
            $collection->taskSsh($worker, $auth)
                ->remoteDirectory($currentProjectRoot, true)
                ->timeout($this->getTimeoutSetting('backup_database'))
                ->exec($dbBackup);
        }
        return $collection;
    }

    /**
     * Remove a backup.
     *
     * @param string $worker
     *   The server to install the site on.
     * @param \DigipolisGent\Robo\Task\Deploy\Ssh\Auth\AbstractAuth $auth
     *   The ssh authentication to connect to the server.
     * @param array $remote
     *   The remote settings for this server.
     *
     * @return \Robo\Contract\TaskInterface
     *   The backup task.
     */
    protected function removeBackupTask(
        $worker,
        AbstractAuth $auth,
        $remote,
        $opts = ['files' => false, 'data' => false]
    ) {
        $backupDir = $remote['backupsdir'] . '/' . $remote['time'];

        $collection = $this->collectionBuilder();
        $collection->taskSsh($worker, $auth)
            ->timeout($this->getTimeoutSetting('remove_backup'))
            ->exec('rm -rf ' . $backupDir);

        return $collection;
    }

    /**
     * Restore a backup of files (storage folder) and database.
     *
     * @param string $worker
     *   The server to install the site on.
     * @param \DigipolisGent\Robo\Task\Deploy\Ssh\Auth\AbstractAuth $auth
     *   The ssh authentication to connect to the server.
     * @param array $remote
     *   The remote settings for this server.
     *
     * @return \Robo\Contract\TaskInterface
     *   The restore backup task.
     */
    protected function restoreBackupTask(
        $worker,
        AbstractAuth $auth,
        $remote,
        $opts = ['files' => false, 'data' => false]
    ) {
        if (!$opts['files'] && !$opts['data']) {
            $opts['files'] = true;
            $opts['data'] = true;
        }

        $currentProjectRoot = $this->getCurrentProjectRoot($worker, $auth, $remote);
        $backupDir = $remote['backupsdir'] . '/' . $remote['time'];

        $collection = $this->collectionBuilder();

        // Restore the files backup.
        $preRestoreBackup = $this->preRestoreBackupTask($worker, $auth, $remote, $opts);
        if ($preRestoreBackup) {
            $collection->addTask($preRestoreBackup);
        }

        if ($opts['files']) {
            $filesBackupFile =  $this->backupFileName('.tar.gz', $remote['time']);
            $collection
                ->taskSsh($worker, $auth)
                    ->remoteDirectory($remote['filesdir'], true)
                    ->timeout($this->getTimeoutSetting('restore_files_backup'))
                    ->exec('tar -xkzf ' . $backupDir . '/' . $filesBackupFile);
        }

        // Restore the db backup.
        if ($opts['data']) {
            $dbBackupFile =  $this->backupFileName('.sql.gz', $remote['time']);
            $dbRestore = 'vendor/bin/robo digipolis:database-restore '
                . '--source=' . $backupDir . '/' . $dbBackupFile;
            $collection
                ->taskSsh($worker, $auth)
                    ->remoteDirectory($currentProjectRoot, true)
                    ->timeout($this->getTimeoutSetting('restore_db_backup'))
                    ->exec($dbRestore);
        }
        return $collection;
    }

    /**
     * Pre restore backup task.
     *
     * @param string $worker
     *   The server to install the site on.
     * @param \DigipolisGent\Robo\Task\Deploy\Ssh\Auth\AbstractAuth $auth
     *   The ssh authentication to connect to the server.
     * @param array $remote
     *   The remote settings for this server.
     *
     * @return bool|\Robo\Contract\TaskInterface
     *   The pre restore backup task, false if no pre restore backup tasks need
     *   to run.
     */
    protected function preRestoreBackupTask(
        $worker,
        AbstractAuth $auth,
        $remote,
        $opts = ['files' => false, 'data' => false]
    ) {
        if (!$opts['files'] && !$opts['data']) {
            $opts['files'] = true;
            $opts['data'] = true;
        }
        if ($opts['files']) {
            $removeFiles = 'rm -rf';
            if (!$this->fileBackupSubDirs) {
                $removeFiles .= ' ./* ./.??*';
            }
            foreach ($this->fileBackupSubDirs as $subdir) {
                $removeFiles .= ' ' . $subdir . '/* ' . $subdir . '/.??*';
            }

            return $this->taskSsh($worker, $auth)
                ->remoteDirectory($remote['filesdir'], true)
                // Files dir can be pretty big on large sites.
                ->timeout($this->getTimeoutSetting('pre_restore_remove_files'))
                ->exec($removeFiles);
        }

        return false;
    }

    /**
     * Download a backup of files (storage folder) and database.
     *
     * @param string $worker
     *   The server to install the site on.
     * @param \DigipolisGent\Robo\Task\Deploy\Ssh\Auth\AbstractAuth $auth
     *   The ssh authentication to connect to the server.
     * @param array $remote
     *   The remote settings for this server.
     *
     * @return \Robo\Contract\TaskInterface
     *   The download backup task.
     */
    protected function downloadBackupTask(
        $worker,
        AbstractAuth $auth,
        $remote,
        $opts = ['files' => false, 'data' => false]
    ) {
        if (!$opts['files'] && !$opts['data']) {
            $opts['files'] = true;
            $opts['data'] = true;
        }
        $backupDir = $remote['backupsdir'] . '/' . $remote['time'];

        $collection = $this->collectionBuilder();
        $collection
            ->taskScp($worker, $auth);

        // Download files.
        if ($opts['files']) {
            $filesBackupFile = $this->backupFileName('.tar.gz', $remote['time']);
            $collection->get($backupDir . '/' . $filesBackupFile, $filesBackupFile);
        }

        // Download data.
        if ($opts['data']) {
            $dbBackupFile = $this->backupFileName('.sql.gz', $remote['time']);
            $collection->get($backupDir . '/' . $dbBackupFile, $dbBackupFile);
        }
        return $collection;
    }

    /**
     * Upload a backup of files (storage folder) and database to a server.
     *
     * @param string $worker
     *   The server to install the site on.
     * @param \DigipolisGent\Robo\Task\Deploy\Ssh\Auth\AbstractAuth $auth
     *   The ssh authentication to connect to the server.
     * @param array $remote
     *   The remote settings for this server.
     *
     * @return \Robo\Contract\TaskInterface
     *   The upload backup task.
     */
    protected function uploadBackupTask(
        $worker,
        AbstractAuth $auth,
        $remote,
        $opts = ['files' => false, 'data' => false]
    ) {
        if (!$opts['files'] && !$opts['data']) {
            $opts['files'] = true;
            $opts['data'] = true;
        }
        $backupDir = $remote['backupsdir'] . '/' . $remote['time'];
        $dbBackupFile = $this->backupFileName('.sql.gz', $remote['time']);
        $filesBackupFile = $this->backupFileName('.tar.gz', $remote['time']);

        $collection = $this->collectionBuilder();
        $collection
            ->taskSsh($worker, $auth)
                ->exec('mkdir -p ' . $backupDir)
            ->taskScp($worker, $auth);
        if ($opts['files']) {
            $collection->put($backupDir . '/' . $filesBackupFile, $filesBackupFile);
        }
        if ($opts['data']) {
            $collection->put($backupDir . '/' . $dbBackupFile, $dbBackupFile);
        }
        return $collection;
    }

    /**
     * Push a package to the server.
     *
     * @param string $worker
     *   The server to install the site on.
     * @param \DigipolisGent\Robo\Task\Deploy\Ssh\Auth\AbstractAuth $auth
     *   The ssh authentication to connect to the server.
     * @param array $remote
     *   The remote settings for this server.
     * @param string|null $archivename
     *   The path to the package to push.
     *
     * @return \Robo\Contract\TaskInterface
     *   The push package task.
     */
    protected function pushPackageTask($worker, AbstractAuth $auth, $remote, $archivename = null)
    {
        $archive = is_null($archivename)
            ? $remote['time'] . '.tar.gz'
            : $archivename;
        $releaseDir = $remote['releasesdir'] . '/' . $remote['time'];
        $collection = $this->collectionBuilder();
        $collection->taskPushPackage($worker, $auth)
            ->destinationFolder($releaseDir)
            ->package($archive);

        return $collection;
    }

    /**
     * Switch the current symlink to the previous release on the server.
     *
     * @param string $worker
     *   The server to install the site on.
     * @param \DigipolisGent\Robo\Task\Deploy\Ssh\Auth\AbstractAuth $auth
     *   The ssh authentication to connect to the server.
     * @param array $remote
     *   The remote settings for this server.
     *
     * @return \Robo\Contract\TaskInterface
     *   The switch previous task.
     */
    protected function switchPreviousTask($worker, AbstractAuth $auth, $remote)
    {
        return $this->taskSsh($worker, $auth)
            ->remoteDirectory($this->getCurrentProjectRoot($worker, $auth, $remote), true)
            ->exec(
                'vendor/bin/robo digipolis:switch-previous '
                . $remote['releasesdir']
                . ' ' . $remote['currentdir']
            );
    }

    /**
     * Remove a failed release from the server.
     *
     * @param string $worker
     *   The server to install the site on.
     * @param \DigipolisGent\Robo\Task\Deploy\Ssh\Auth\AbstractAuth $auth
     *   The ssh authentication to connect to the server.
     * @param array $remote
     *   The remote settings for this server.
     * @param string|null $releaseDirname
     *   The path of the release dir to remove.
     *
     * @return \Robo\Contract\TaskInterface
     *   The remove release task.
     */
    protected function removeFailedRelease($worker, AbstractAuth $auth, $remote, $releaseDirname = null)
    {
        $releaseDir = is_null($releaseDirname)
            ? $remote['releasesdir'] . '/' . $remote['time']
            : $releaseDirname;
        return $this->taskSsh($worker, $auth)
            ->remoteDirectory($remote['rootdir'], true)
            ->exec('chown -R ' . $remote['user'] . ':' . $remote['user'] . ' ' . $releaseDir)
            ->exec('chmod -R a+rwx ' . $releaseDir)
            ->exec('rm -rf ' . $releaseDir);
    }

    /**
     * Create all required symlinks on the server.
     *
     * @param string $worker
     *   The server to install the site on.
     * @param \DigipolisGent\Robo\Task\Deploy\Ssh\Auth\AbstractAuth $auth
     *   The ssh authentication to connect to the server.
     * @param array $remote
     *   The remote settings for this server.
     *
     * @return \Robo\Contract\TaskInterface
     *   The symlink task.
     */
    protected function symlinksTask($worker, AbstractAuth $auth, $remote)
    {
        $collection = $this->collectionBuilder();
        foreach ($remote['symlinks'] as $link) {
            $collection->taskSsh($worker, $auth)
                ->exec('ln -s -T -f ' . str_replace(':', ' ', $link));
        }
        return $collection;
    }

    /**
     * Clear OPcache on the server.
     *
     * @param string $worker
     *   The server to install the site on.
     * @param \DigipolisGent\Robo\Task\Deploy\Ssh\Auth\AbstractAuth $auth
     *   The ssh authentication to connect to the server.
     * @param array $remote
     *   The remote settings for this server.
     *
     * @return \Robo\Contract\TaskInterface
     *   The clear OPcache task.
     */
    protected function clearOpCacheTask($worker, AbstractAuth $auth, $remote)
    {
        $clearOpcache = 'vendor/bin/robo digipolis:clear-op-cache ' . $remote['opcache']['env'];
        if (isset($remote['opcache']['host'])) {
            $clearOpcache .= ' --host=' . $remote['opcache']['host'];
        }
        return $this->taskSsh($worker, $auth)
            ->remoteDirectory($remote['rootdir'], true)
            ->exec($clearOpcache);
    }

    /**
     * Clean the release and backup directories on the server.
     *
     * @param string $worker
     *   The server to install the site on.
     * @param \DigipolisGent\Robo\Task\Deploy\Ssh\Auth\AbstractAuth $auth
     *   The ssh authentication to connect to the server.
     * @param array $remote
     *   The remote settings for this server.
     *
     * @return \Robo\Contract\TaskInterface
     *   The clean directories task.
     */
    protected function cleanDirsTask($worker, AbstractAuth $auth, $remote)
    {
        $cleandirLimit = isset($remote['cleandir_limit']) ? max(1, $remote['cleandir_limit']) : '';

        $task = $this->taskSsh($worker, $auth)
            ->remoteDirectory($remote['rootdir'], true)
            ->timeout($this->getTimeoutSetting('clean_dir'))
            // Keep one more release than the clean_dir setting, we would like
            // to keep `clean_dir` amount of releases, *not* counting the
            // current one.
            ->exec('vendor/bin/robo digipolis:clean-dir ' . $remote['releasesdir'] . ($cleandirLimit ? ':' . ($cleandirLimit + 1) : ''));

        if ($remote['createbackup']) {
            $task->exec('vendor/bin/robo digipolis:clean-dir ' . $remote['backupsdir'] . ($cleandirLimit ? ':' . $cleandirLimit : ''));
        }

        return $task;
    }

    /**
     * Sync the database and files to your local environment.
     *
     * @param string $user
     *   SSH user to connect to the source server.
     * @param string $host
     *   IP address of the source server.
     * @param string $keyFile
     *   Private key file to use to connect to the source server.
     * @param array $opts
     *   Command options
     *
     * @option app The name of the app we're syncing.
     * @option files Sync only files.
     * @option data Sync only the database.
     * @option rsync Sync the files via rsync.
     *
     * @return \Robo\Contract\TaskInterface
     *   The sync task.
     */
    public function digipolisSyncLocal(
        $host,
        $user,
        $keyFile,
        $opts = [
            'app' => 'default',
            'files' => false,
            'data' => false,
            'rsync' => true,
        ]
    ) {
        if (!$opts['files'] && !$opts['data']) {
            $opts['files'] = true;
            $opts['data'] = true;
        }

        $opts['rsync'] = !isset($opts['rsync']) || $opts['rsync'];

        $remote = $this->getRemoteSettings($host, $user, $keyFile, $opts['app']);
        $local = $this->getLocalSettings($opts['app']);
        $auth = new KeyFile($user, $keyFile);
        $collection = $this->collectionBuilder();

        if ($opts['files']) {
            $collection
                ->taskExecStack()
                    ->exec('chown -R $USER ' . dirname($local['filesdir']))
                    ->exec('chmod -R u+w ' . dirname($local['filesdir']));

            if ($opts['rsync']) {
                $opts['files'] = false;

                $dirs = ($this->fileBackupSubDirs ? $this->fileBackupSubDirs : ['']);

                foreach ($dirs as $dir) {
                    $dir .= ($dir !== '' ? '/' : '');

                    $rsync = $this->taskRsync()
                        ->rawArg('--rsh "ssh -o StrictHostKeyChecking=no -i `vendor/bin/robo digipolis:realpath ' . $keyFile . '`"')
                        ->fromHost($host)
                        ->fromUser($user)
                        ->fromPath($remote['filesdir'] . '/' . $dir)
                        ->toPath($local['filesdir'] . '/' . $dir)
                        ->archive()
                        ->delete()
                        ->rawArg('--copy-links --keep-dirlinks')
                        ->compress()
                        ->checksum()
                        ->wholeFile();

                    foreach ($this->excludeFromBackup as $exclude) {
                        $rsync->exclude($exclude);
                    }

                    $collection->addTask($rsync);
                }
            }
        }

        if ($opts['data'] || $opts['files']) {
            // Create a backup.
            $collection->addTask(
                $this->backupTask(
                    $host,
                    $auth,
                    $remote,
                    $opts
                )
            );
            // Download the backup.
            $collection->addTask(
                $this->downloadBackupTask(
                    $host,
                    $auth,
                    $remote,
                    $opts
                )
            );
        }

        if ($opts['files']) {
            // Restore the files backup.
            $filesBackupFile =  $this->backupFileName('.tar.gz', $remote['time']);
            $collection
                ->exec('rm -rf ' . $local['filesdir'] . '/* ' . $local['filesdir'] . '/.??*')
                ->exec('tar -xkzf ' . $filesBackupFile . ' -C ' . $local['filesdir'])
                ->exec('rm -f ' . $filesBackupFile);
        }

        if ($opts['data']) {
            // Restore the db backup.
            $dbBackupFile =  $this->backupFileName('.sql.gz', $remote['time']);
            $dbRestore = 'vendor/bin/robo digipolis:database-restore '
                . '--source=' . $dbBackupFile;
            $cwd = getcwd();

            $collection->taskExecStack();
            $collection->exec('cd ' . $this->getConfig()->get('digipolis.root.project') . ' && ' . $dbRestore);
            $collection->exec('cd ' . $cwd . ' && rm -f ' . $dbBackupFile);
        }

        return $collection;
    }

    /**
     * Helper functions to replace tokens in an array.
     *
     * @param string|array $input
     *   The array or string containing the tokens to replace.
     * @param array $replacements
     *   The token replacements.
     *
     * @return string|array
     *   The input with the tokens replaced with their values.
     */
    protected function tokenReplace($input, $replacements)
    {
        if (is_string($input)) {
            return strtr($input, $replacements);
        }
        if (is_scalar($input) || empty($input)) {
            return $input;
        }
        foreach ($input as &$i) {
            $i = $this->tokenReplace($i, $replacements);
        }
        return $input;
    }

    /**
     * Generate a backup filename based on the given time.
     *
     * @param string $extension
     *   The extension to append to the filename. Must include leading dot.
     * @param int|null $timestamp
     *   The timestamp to generate the backup name from. Defaults to the request
     *   time.
     *
     * @return string
     *   The generated filename.
     */
    protected function backupFileName($extension, $timestamp = null)
    {
        if (is_null($timestamp)) {
            $timestamp = $this->time;
        }
        return $timestamp . '_' . date('Y_m_d_H_i_s', $timestamp) . $extension;
    }

    /**
     * Get the settings from the 'remote' config key, with the tokens replaced.
     *
     * @param string $host
     *   The IP address of the server to get the settings for.
     * @param string $user
     *   The SSH user used to connect to the server.
     * @param string $keyFile
     *   The path to the private key file used to connect to the server.
     * @param string $app
     *   The name of the app these settings apply to.
     * @param string|null $timestamp
     *   The timestamp to use. Defaults to the request time.
     *
     * @return array
     *   The settings for this server and app.
     */
    protected function getRemoteSettings($host, $user, $keyFile, $app, $timestamp = null)
    {
        $this->readProperties();
        $defaults = [
            'user' => $user,
            'private-key' => $keyFile,
            'app' => $app,
            'createbackup' => true,
            'time' => is_null($timestamp) ? $this->time : $timestamp,
        ];

        // Set up destination config.
        $replacements = array(
            '[user]' => $user,
            '[private-key]' => $keyFile,
            '[app]' => $app,
            '[time]' => is_null($timestamp) ? $this->time : $timestamp,
        );
        if (is_string($host)) {
            $replacements['[server]'] = $host;
            $defaults['server'] = $host;
        }
        if (is_array($host)) {
            foreach ($host as $key => $server) {
                $replacements['[server-' . $key . ']'] = $server;
                $defaults['server-' . $key] = $server;
            }
        }
        return $this->tokenReplace($this->getConfig()->get('remote'), $replacements) + $defaults;
    }

    /**
     * Get the settings from the 'local' config key, with the tokens replaced.
     *
     * @param string $app
     *   The name of the app these settings apply to.
     * @param string|null $timestamp
     *   The timestamp to use. Defaults to the request time.
     *
     * @return array
     *   The settings for the local environment and app.
     */
    protected function getLocalSettings($app = null, $timestamp = null)
    {
        $this->readProperties();
        $defaults = [
            'app' => $app,
            'time' => is_null($timestamp) ? $this->time : $timestamp,
            'project_root' => $this->getConfig()->get('digipolis.root.project'),
            'web_root' => $this->getConfig()->get('digipolis.root.web'),
        ];

        // Set up destination config.
        $replacements = array(
            '[project_root]' => $this->getConfig()->get('digipolis.root.project'),
            '[web_root]' => $this->getConfig()->get('digipolis.root.web'),
            '[app]' => $app,
            '[time]' => is_null($timestamp) ? $this->time : $timestamp,
        );
        return $this->tokenReplace($this->getConfig()->get('local'), $replacements) + $defaults;
    }

    /**
     * Timeouts can be overwritten in properties.yml under the `timeout` key.
     *
     * @param string $setting
     *
     * @return int
     */
    protected function getTimeoutSetting($setting)
    {
        $timeoutSettings = $this->getTimeoutSettings();
        return isset($timeoutSettings[$setting]) ? $timeoutSettings[$setting] : static::DEFAULT_TIMEOUT;
    }

    protected function getTimeoutSettings()
    {
        $this->readProperties();
        return $this->getConfig()->get('timeouts', []) + $this->getDefaultTimeoutSettings();
    }

    protected function getDefaultTimeoutSettings()
    {
        return [
            'presymlink_mirror_dir' => 60,
            'synctask_rsync' => 1800,
            'backup_files' => 300,
            'backup_database' => 300,
            'remove_backup' => 300,
            'restore_files_backup' => 300,
            'restore_db_backup' => 60,
            'pre_restore_remove_files' => 300,
            'clean_dir' => 30,
        ];
    }

    public function digipolisRealpath($path) {
        return $this->realpath($path);
    }

    /**
     * PHP's realpath can't handle tilde (~), so we have to write a wrapper
     * for it.
     */
    protected function realpath($path)
    {
        $realpath = $path;
        if (strpos($realpath, '~') === 0 && ($homedir = $this->getUserHomeDir())) {
            $realpath = $homedir . substr($realpath, 1);
        }
        $realpath = realpath($realpath);

        if ($realpath === false) {
            throw new \Exception(sprintf('Could not determine real path of %s.', $path));
        }

        return $realpath;
    }

    /**
     * Get the home directory for the current user.
     */
    protected function getUserHomeDir()
    {
        // getenv('HOME') isn't set on Windows.
        $home = getenv('HOME');
        if (!empty($home)) {
            // home should never end with a trailing slash.
            return rtrim($home, '/');
        }
        if (!empty($_SERVER['HOMEDRIVE']) && !empty($_SERVER['HOMEPATH'])) {
            // home on windows
            $home = $_SERVER['HOMEDRIVE'] . $_SERVER['HOMEPATH'];
            // If HOMEPATH is a root directory the path can end with a slash.
            // Make sure that doesn't happen.
            return rtrim($home, '\\/');
        }

        throw new \Exception('Could not determine the current user\'s home directory.');
    }
}
