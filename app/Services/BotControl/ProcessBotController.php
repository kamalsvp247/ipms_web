<?php

namespace App\Services\BotControl;

use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class ProcessBotController
{
    protected string $jarPath;

    protected string $cwd;

    protected string $pidFile;

    protected string $metaFile;

    protected string $logFile;

    protected string $processPattern;

    protected bool $useMavenExec;

    protected string $mainClass;

    public function __construct()
    {
        $this->jarPath = config('bot.jar_path');
        $this->cwd = config('bot.cwd');
        $this->pidFile = config('bot.pid_file');
        $this->metaFile = config('bot.meta_file');
        $this->logFile = config('bot.log_file');
        $this->processPattern = config('bot.process_pattern');
        $this->useMavenExec = (bool) config('bot.use_maven_exec', true);
        $this->mainClass = config('bot.main_class', 'com.ivac.booking.App');
    }

    /**
     * Check if the bot process is currently running.
     */
    public function status(): array
    {
        $pid = $this->readPid();

        if ($pid && $this->isProcessRunning($pid)) {
            if ($this->isProcessOurBot($pid)) {
                $meta = $this->readMeta();

                return [
                    'running' => true,
                    'pid' => $pid,
                    'started_at' => $meta['started_at'] ?? null,
                    'message' => "Bot is running (PID {$pid}).",
                ];
            }
            // PID exists but is not our bot — clear stale state
            $this->clearPidFile();
            $this->clearMetaFile();
            $pid = null;
        }

        if ($pid) {
            $this->clearPidFile();
            $this->clearMetaFile();
        }

        $discoveredPid = $this->discoverPid();
        if ($discoveredPid) {
            $this->writePid($discoveredPid);

            return [
                'running' => true,
                'pid' => $discoveredPid,
                'started_at' => null,
                'message' => "Bot is running (PID {$discoveredPid}, discovered dynamically).",
            ];
        }

        return [
            'running' => false,
            'pid' => null,
            'started_at' => null,
            'message' => 'Bot is not running.',
        ];
    }

    /**
     * Start the bot as a background process.
     *
     * @param  string|null  $configUrl  Full URL for the bot to fetch config (e.g. from APP_URL). When set, passed as IPMS_CONFIG_URL to the Java process.
     */
    public function start(?string $configUrl = null): array
    {
        $current = $this->status();
        if ($current['running']) {
            return [
                'success' => false,
                'message' => "Bot is already running (PID {$current['pid']}).",
            ];
        }

        $mvn = $this->getMavenExecutable();

        // Ensure log directory exists and clear previous log
        $logDir = dirname($this->logFile);
        if (! is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        @file_put_contents($this->logFile, '['.date('Y-m-d H:i:s')."] Bot starting...\n");

        if ($this->isWindows()) {
            return $this->startWindows($mvn, $configUrl);
        }

        // Linux start
        // Load JAVA_OPTS from environment for JVM optimization
        $javaOpts = env('JAVA_OPTS', '');

        if ($this->useMavenExec) {
            $mavenOptsEnv = $javaOpts ? 'MAVEN_OPTS='.escapeshellarg($javaOpts).' ' : '';
            $command = sprintf(
                '%snohup %s exec:java -Dexec.mainClass=%s >> %s 2>&1 & echo $!',
                $mavenOptsEnv,
                $mvn,
                escapeshellarg($this->mainClass),
                escapeshellarg($this->logFile)
            );
        } else {
            $javaOptsStr = $javaOpts ? $javaOpts.' ' : '';
            $command = sprintf(
                'nohup %sjava -jar %s >> %s 2>&1 & echo $!',
                $javaOptsStr,
                escapeshellarg($this->jarPath),
                escapeshellarg($this->logFile)
            );
        }

        $process = Process::fromShellCommandline($command, $this->cwd);
        if ($configUrl !== null && $configUrl !== '') {
            $env = array_merge(is_array(getenv()) ? getenv() : [], ['IPMS_CONFIG_URL' => $configUrl]);
            $process->setEnv($env);
        }
        $process->run();

        $output = trim($process->getOutput());
        $pid = (int) $output;

        if ($pid > 0 && $this->isProcessRunning($pid)) {
            $this->writePid($pid);
            $this->writeMeta($pid, now()->toIso8601String());

            return [
                'success' => true,
                'message' => "Bot started successfully (PID {$pid}).",
                'pid' => $pid,
            ];
        }

        return $this->buildStartFailureResponse('Failed to start bot.');
    }

    /**
     * Start the bot on Windows using a .bat launcher + WMI Win32_Process.Create.
     *
     * A .bat file is written first with the full command, then WMI runs it in a
     * detached hidden window. This avoids nested quoting issues when paths
     * contain spaces (e.g. IntelliJ IDEA directory).
     */
    protected function startWindows(string $mvn, ?string $configUrl = null): array
    {
        $javaOpts = env('JAVA_OPTS', '');

        if ($this->useMavenExec) {
            $executable = $mvn;
            if (! str_ends_with(strtolower($executable), '.cmd') && ! str_ends_with(strtolower($executable), '.bat')) {
                if ($executable === 'mvn') {
                    $executable = 'mvn.cmd';
                }
            }
            $mavenOpts = $javaOpts ? "set \"MAVEN_OPTS={$javaOpts}\"\r\n" : '';
            $commandLine = "\"{$executable}\" exec:java \"-Dexec.mainClass={$this->mainClass}\"";
        } else {
            $commandLine = $javaOpts ? "{$javaOpts} java -jar \"{$this->jarPath}\"" : "java -jar \"{$this->jarPath}\"";
        }

        $logFile = str_replace('/', '\\', $this->logFile);
        $cwd = str_replace('/', '\\', $this->cwd);

        $batFile = sys_get_temp_dir().'\\ipms_start_bot.bat';
        $batLines = ["@echo off\r\n", "cd /D \"{$cwd}\"\r\n"];
        if ($this->useMavenExec && $javaOpts) {
            $batLines[] = "set \"MAVEN_OPTS={$javaOpts}\"\r\n";
        }
        if ($configUrl !== null && $configUrl !== '') {
            $escaped = str_replace('%', '%%', $configUrl);
            $batLines[] = "set \"IPMS_CONFIG_URL={$escaped}\"\r\n";
        }
        $batLines[] = "{$commandLine} >> \"{$logFile}\" 2>&1\r\n";
        $batContent = implode('', $batLines);
        file_put_contents($batFile, $batContent);

        $batFileEscaped = str_replace("'", "''", $batFile);
        $psScript = <<<PS
\$wmi = ([wmiclass]"Win32_Process")
\$startup = ([wmiclass]"Win32_ProcessStartup")
\$startupConfig = \$startup.CreateInstance()
\$startupConfig.ShowWindow = 0
\$result = \$wmi.Create('cmd /C "{$batFileEscaped}"', '{$cwd}', \$startupConfig)
Write-Output \$result.ProcessId
PS;

        $psFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ipms_start_bot.ps1';
        file_put_contents($psFile, $psScript);

        try {
            $command = 'powershell -ExecutionPolicy Bypass -File '.escapeshellarg($psFile);
            $process = Process::fromShellCommandline($command);
            $process->setTimeout(15);
            $process->run();

            $output = trim($process->getOutput());
            $pid = (int) $output;
        } finally {
            @unlink($psFile);
        }

        if ($pid > 0) {
            usleep(3_000_000);

            $javaPid = $this->findChildJavaPid($pid);
            $finalPid = $javaPid ?: $pid;

            if ($this->isProcessRunning($finalPid)) {
                $this->writePid($finalPid);
                $this->writeMeta($finalPid, now()->toIso8601String());

                return [
                    'success' => true,
                    'message' => "Bot started successfully (PID {$finalPid}).",
                    'pid' => $finalPid,
                ];
            }

            return $this->buildStartFailureResponse('Bot process exited immediately.');
        }

        usleep(1_000_000);
        $discovered = $this->discoverPid();
        if ($discovered) {
            $this->writePid($discovered);
            $this->writeMeta($discovered, now()->toIso8601String());

            return [
                'success' => true,
                'message' => "Bot started successfully (PID {$discovered}).",
                'pid' => $discovered,
            ];
        }

        return $this->buildStartFailureResponse('Failed to start bot.');
    }

    /**
     * Find a child Java process spawned by a parent CMD process (Windows).
     */
    protected function findChildJavaPid(int $parentPid): ?int
    {
        $output = $this->runPowerShellScript(<<<'PS'
Get-CimInstance Win32_Process | Where-Object { $_.ParentProcessId -eq $PARENT_PID } | ForEach-Object {
    $_.ProcessId.ToString()
}
PS, ['$PARENT_PID' => $parentPid]);

        foreach (explode("\n", trim($output)) as $line) {
            $childPid = (int) trim($line);
            if ($childPid > 0) {
                return $childPid;
            }
        }

        return null;
    }

    public function stop(): array
    {
        return $this->sendSignal($this->isWindows() ? 0 : 15, 'stop');
    }

    public function releaseThreads(): array
    {
        return $this->sendSignal($this->isWindows() ? 0 : 15, 'release-threads');
    }

    public function kill(): array
    {
        $pid = $this->readPid();
        $killed = false;

        if ($pid && $this->isProcessRunning($pid)) {
            if ($this->isWindows()) {
                Process::fromShellCommandline("taskkill /F /PID $pid /T")->run();
            } elseif (function_exists('posix_kill')) {
                @posix_kill($pid, 9);
            } else {
                Process::fromShellCommandline("kill -9 $pid 2>/dev/null")->run();
            }
            $killed = true;
        }

        if ($this->isWindows()) {
            // Also kill any java processes matching our pattern
            $allJava = $this->getWindowsJavaProcesses();
            foreach ($allJava as $proc) {
                if (str_contains($proc['command'], $this->processPattern)) {
                    Process::fromShellCommandline("taskkill /F /PID {$proc['pid']} /T")->run();
                    $killed = true;
                }
            }
        } else {
            try {
                Process::fromShellCommandline(sprintf('pkill -9 -f %s 2>/dev/null; true', escapeshellarg($this->processPattern)))->run();
            } catch (\Symfony\Component\Process\Exception\ProcessSignaledException $e) {
                // pkill -9 may kill the php-fpm worker handling this request;
                // signal 9 exit means the kill succeeded — treat as success.
            }
        }

        $this->clearPidFile();
        $this->clearMetaFile();

        return [
            'success' => true,
            'message' => $killed ? "Killed bot (PID {$pid})." : 'Bot process cleaned up.',
        ];
    }

    protected function sendSignal(int $signal, string $action): array
    {
        $current = $this->status();
        if (! $current['running']) {
            return ['success' => false, 'message' => 'Bot is not running.'];
        }

        $pid = $current['pid'];

        if ($this->isWindows()) {
            $sent = Process::fromShellCommandline("taskkill /PID $pid")->run() === 0;
        } elseif (function_exists('posix_kill')) {
            $sent = posix_kill($pid, $signal);
            // posix_kill fails when php-fpm (www-data) tries to signal a process owned by another user (e.g. root).
            // Fall back to sudo kill so the portal can stop a bot started outside of it.
            if (! $sent) {
                $process = Process::fromShellCommandline("sudo /bin/kill -{$signal} $pid 2>/dev/null");
                $process->run();
                $sent = $process->getExitCode() === 0;
            }
        } else {
            $process = Process::fromShellCommandline("kill -{$signal} $pid 2>/dev/null");
            $process->run();
            $sent = $process->getExitCode() === 0;
        }

        if ($sent) {
            usleep(500_000);
            if (! $this->isProcessRunning($pid)) {
                $this->clearPidFile();
                $this->clearMetaFile();
            }

            return ['success' => true, 'message' => ucfirst($action).' command sent.'];
        }

        return ['success' => false, 'message' => "Failed to send {$action} command."];
    }

    /**
     * Truncate the bot log file. If the bot is running, new output will continue to be appended.
     */
    public function clearLogs(): array
    {
        $logDir = dirname($this->logFile);
        if (! is_dir($logDir)) {
            return ['success' => false, 'message' => 'Log directory does not exist.'];
        }

        if (file_exists($this->logFile) && ! is_writable($this->logFile)) {
            return ['success' => false, 'message' => 'Log file is not writable.'];
        }

        $content = '['.date('Y-m-d H:i:s')."] Log cleared.\n";
        if (file_put_contents($this->logFile, $content) === false) {
            return ['success' => false, 'message' => 'Failed to clear log file.'];
        }

        return ['success' => true, 'message' => 'Log file cleared.'];
    }

    /**
     * Path to the bot log file (for full download).
     */
    public function getLogFilePath(): string
    {
        return $this->logFile;
    }

    public function getLogs(int $lines = 100): array
    {
        if (! file_exists($this->logFile)) {
            return ['content' => '', 'message' => 'Log file not found.'];
        }

        // On Linux, use tail for efficiency with large log files
        if (! $this->isWindows()) {
            $process = Process::fromShellCommandline(sprintf(
                'tail -n %d %s',
                $lines,
                escapeshellarg($this->logFile)
            ));
            $process->run();

            return [
                'content' => $process->getOutput(),
                'message' => null,
            ];
        }

        $full = @file_get_contents($this->logFile) ?: '';
        $allLines = explode("\n", $full);
        $lastLines = array_slice($allLines, -$lines);

        return [
            'content' => implode("\n", $lastLines),
            'message' => null,
        ];
    }

    public function validateSetup(): array
    {
        $checks = [];
        $ok = true;

        if (! is_dir($this->cwd)) {
            $checks[] = ['name' => 'Working Directory', 'ok' => false, 'message' => "Directory not found: {$this->cwd}"];
            $ok = false;
        } else {
            $checks[] = ['name' => 'Working Directory', 'ok' => true, 'message' => 'Working directory exists.'];
        }

        if ($this->useMavenExec) {
            $mvn = $this->getMavenExecutable();
            // Handle windows .cmd if needed
            $mvnCmd = $this->isWindows() && ! str_ends_with(strtolower($mvn), '.cmd') && ! str_ends_with(strtolower($mvn), '.bat') && $mvn !== 'mvn' ? $mvn : ($this->isWindows() && $mvn === 'mvn' ? 'mvn.cmd' : $mvn);

            $process = Process::fromShellCommandline(escapeshellarg($mvnCmd).' -v 2>&1');
            $process->run();
            if ($process->getExitCode() !== 0) {
                $checks[] = ['name' => 'Maven', 'ok' => false, 'message' => "Could not execute Maven at: $mvn"];
                $ok = false;
            } else {
                $checks[] = ['name' => 'Maven', 'ok' => true, 'message' => 'Maven is accessible.'];
            }
        }

        $process = Process::fromShellCommandline('java -version 2>&1');
        $process->run();
        if ($process->getExitCode() !== 0) {
            $checks[] = ['name' => 'Java', 'ok' => false, 'message' => 'Java not found on PATH.'];
            $ok = false;
        } else {
            $checks[] = ['name' => 'Java', 'ok' => true, 'message' => 'Java is accessible.'];
        }

        return ['valid' => $ok, 'checks' => $checks];
    }

    /**
     * Check if the bot Java classes are compiled.
     */
    public function isCompiled(): bool
    {
        $classFile = $this->cwd.'/target/classes/'.str_replace('.', '/', $this->mainClass).'.class';

        return file_exists($classFile);
    }

    /**
     * Compile the bot project using Maven.
     *
     * @return array{success: bool, message: string, output: string}
     */
    public function compile(): array
    {
        if (! is_dir($this->cwd)) {
            return [
                'success' => false,
                'message' => "Working directory not found: {$this->cwd}",
                'output' => '',
            ];
        }

        // Fix ownership before compiling so Maven can write to target/ even if
        // the directory was previously created by root (e.g. manual mvn run)
        if (! $this->isWindows()) {
            Process::fromShellCommandline('chown -R www-data:www-data '.escapeshellarg($this->cwd))->run();
        }

        $mvn = $this->getMavenExecutable();

        if ($this->isWindows()) {
            if (! str_ends_with(strtolower($mvn), '.cmd') && ! str_ends_with(strtolower($mvn), '.bat') && $mvn === 'mvn') {
                $mvn = 'mvn.cmd';
            }
        }

        $command = escapeshellarg($mvn).' compile 2>&1';
        $process = Process::fromShellCommandline($command, $this->cwd);
        $process->setTimeout(300);
        $process->run();

        $output = $process->getOutput();
        $success = $process->getExitCode() === 0;

        // Fix ownership of target/ so www-data can write on subsequent compiles
        // (target/ may be owned by root if mvn was run manually as root)
        if (! $this->isWindows()) {
            $targetDir = $this->cwd.'/target';
            if (is_dir($targetDir)) {
                Process::fromShellCommandline('chown -R www-data:www-data '.escapeshellarg($targetDir))->run();
            }
        }

        return [
            'success' => $success,
            'message' => $success ? 'Compilation successful.' : 'Compilation failed.',
            'output' => $output,
        ];
    }

    /**
     * Build a fat JAR for VPS distribution via mvn package -DskipTests.
     *
     * @return array{success: bool, message: string, output: string}
     */
    public function package(): array
    {
        if (! is_dir($this->cwd)) {
            return [
                'success' => false,
                'message' => "Working directory not found: {$this->cwd}",
                'output' => '',
            ];
        }

        $mvn = $this->getMavenExecutable();

        if ($this->isWindows() && ! str_ends_with(strtolower($mvn), '.cmd') && ! str_ends_with(strtolower($mvn), '.bat') && $mvn === 'mvn') {
            $mvn = 'mvn.cmd';
        }

        // Fix any root-owned files leftover from manual mvn runs so www-data can write.
        if (! $this->isWindows()) {
            Process::fromShellCommandline('sudo chown -R www-data:www-data '.escapeshellarg($this->cwd).' 2>/dev/null || true')->run();
        }

        // Use -Dmaven.test.skip=true to skip both test compilation and execution.
        // Broken test files (reference removed packages) cause compilation failures with -DskipTests.
        $process = Process::fromShellCommandline(escapeshellarg($mvn).' clean package -Dmaven.test.skip=true 2>&1', $this->cwd);
        $process->setTimeout(300);
        // Ensure JAVA_HOME is set for Maven to use JDK 26
        $process->setEnv(array_merge($_ENV, ['JAVA_HOME' => '/usr/lib/jvm/jdk-26']));
        $process->run();

        $output = $process->getOutput();
        $success = $process->getExitCode() === 0;

        return [
            'success' => $success,
            'message' => $success ? 'JAR built successfully.' : 'Build failed.',
            'output' => $output,
        ];
    }

    /**
     * Check whether the distributable fat JAR exists.
     */
    public function jarExists(): bool
    {
        return is_file($this->jarPath);
    }

    /**
     * Get the path to the distributable fat JAR.
     */
    public function getJarPath(): string
    {
        return $this->jarPath;
    }

    /**
     * Build a failure response for bot start, including the last portion of the
     * log file so the caller can see exactly why the process failed.
     *
     * @return array{success: bool, message: string, log: string}
     */
    protected function buildStartFailureResponse(string $reason): array
    {
        $logContent = @file_get_contents($this->logFile) ?: '';
        $logTail = $logContent !== '' ? mb_substr($logContent, -2000) : '';

        return [
            'success' => false,
            'message' => $reason,
            'log' => $logTail,
        ];
    }

    // --- Helpers ---

    protected function isWindows(): bool
    {
        return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }

    protected function isProcessRunning(int $pid): bool
    {
        if ($this->isWindows()) {
            $process = Process::fromShellCommandline("tasklist /FI \"PID eq $pid\" /NH");
            $process->run();

            return str_contains($process->getOutput(), (string) $pid);
        }

        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }

        // Shell fallback: kill -0 checks if process exists without sending a signal
        $process = Process::fromShellCommandline("kill -0 $pid 2>/dev/null");
        $process->run();

        return $process->getExitCode() === 0;
    }

    protected function isProcessOurBot(int $pid): bool
    {
        $cmdline = $this->getProcessCmdline($pid);

        return $cmdline && str_contains($cmdline, $this->processPattern);
    }

    /**
     * Get the command line of a process by PID.
     * Uses PowerShell on Windows (wmic outputs UTF-16 which breaks PHP string parsing).
     */
    protected function getProcessCmdline(int $pid): ?string
    {
        if ($this->isWindows()) {
            $output = $this->runPowerShellScript(<<<'PS'
$p = Get-CimInstance Win32_Process | Where-Object { $_.ProcessId -eq $TARGET_PID }
if ($p) { $p.CommandLine } else { "" }
PS, ['$TARGET_PID' => $pid]);

            $result = trim($output);

            return $result !== '' ? $result : null;
        }

        $procFile = "/proc/{$pid}/cmdline";
        if (file_exists($procFile) && is_readable($procFile)) {
            return str_replace("\0", ' ', file_get_contents($procFile));
        }

        return null;
    }

    /**
     * Discover bot PID by searching for java processes matching our pattern.
     * Works on both Windows (PowerShell) and Linux (pgrep).
     */
    protected function discoverPid(): ?int
    {
        if ($this->isWindows()) {
            $allJava = $this->getWindowsJavaProcesses();
            foreach ($allJava as $proc) {
                if (str_contains($proc['command'], $this->processPattern)) {
                    return $proc['pid'];
                }
            }

            return null;
        }

        $process = Process::fromShellCommandline(sprintf('pgrep -f %s 2>/dev/null | head -1', escapeshellarg($this->processPattern)));
        $process->run();
        $pid = (int) trim($process->getOutput());

        // Validate PID with /proc cmdline to avoid false positives from shell wrappers
        if ($pid > 0 && $this->isProcessOurBot($pid)) {
            return $pid;
        }

        return null;
    }

    /**
     * Get all running Java processes on Windows via PowerShell.
     * Returns an array of [pid, command, memory] entries.
     *
     * @return array<int, array{pid: int, command: string, memory: string}>
     */
    protected function getWindowsJavaProcesses(): array
    {
        $output = $this->runPowerShellScript(<<<'PS'
Get-CimInstance Win32_Process | Where-Object { $_.Name -eq 'java.exe' } | ForEach-Object {
    "$($_.ProcessId)||$($_.CommandLine)||$($_.WorkingSetSize)"
}
PS);

        $processes = [];

        foreach (explode("\n", trim($output)) as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            $parts = explode('||', $line, 3);
            if (count($parts) < 3) {
                continue;
            }

            $pid = (int) $parts[0];
            $command = trim($parts[1]);
            $memoryBytes = (int) $parts[2];
            $memoryMb = round($memoryBytes / 1024 / 1024, 1);

            if ($pid > 0) {
                $processes[] = [
                    'pid' => $pid,
                    'command' => $command,
                    'memory' => $memoryMb.' MB',
                ];
            }
        }

        return $processes;
    }

    /**
     * Run a PowerShell script via a temp .ps1 file to avoid escaping issues.
     * Variable placeholders (e.g. $TARGET_PID) are replaced before execution.
     *
     * @param  array<string, mixed>  $variables
     */
    protected function runPowerShellScript(string $script, array $variables = []): string
    {
        foreach ($variables as $placeholder => $value) {
            $script = str_replace($placeholder, (string) $value, $script);
        }

        $psFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ipms_'.uniqid().'.ps1';
        file_put_contents($psFile, $script);

        try {
            $process = Process::fromShellCommandline(
                'powershell -ExecutionPolicy Bypass -File '.escapeshellarg($psFile)
            );
            $process->setTimeout(15);
            $process->run();

            return $process->getOutput();
        } finally {
            @unlink($psFile);
        }
    }

    protected function getMavenExecutable(): string
    {
        return Setting::instance()->maven_path ?: 'mvn';
    }

    protected function readPid(): ?int
    {
        return file_exists($this->pidFile) ? (int) trim(file_get_contents($this->pidFile)) : null;
    }

    protected function writePid(int $pid): void
    {
        file_put_contents($this->pidFile, (string) $pid);
    }

    protected function clearPidFile(): void
    {
        if (file_exists($this->pidFile)) {
            @unlink($this->pidFile);
        }
    }

    protected function readMeta(): array
    {
        if (! file_exists($this->metaFile)) {
            return [];
        }

        return json_decode(file_get_contents($this->metaFile), true) ?: [];
    }

    protected function writeMeta(int $pid, string $startedAt): void
    {
        file_put_contents($this->metaFile, json_encode(['pid' => $pid, 'started_at' => $startedAt]));
    }

    protected function clearMetaFile(): void
    {
        if (file_exists($this->metaFile)) {
            @unlink($this->metaFile);
        }
    }

    /**
     * List all running Java processes with PID, command line, and memory usage.
     *
     * @return array{processes: array<int, array{pid: int, command: string, memory: string}>}
     */
    public function listProcesses(): array
    {
        if ($this->isWindows()) {
            return ['processes' => $this->getWindowsJavaProcesses()];
        }

        $processes = [];

        // Linux: list all java/maven processes matching our bot pattern
        $grepPattern = 'java|'.preg_quote($this->processPattern, '/');
        $process = Process::fromShellCommandline('ps -eo pid,rss,args --no-headers | grep -E '.escapeshellarg($grepPattern).' | grep -v grep');
        $process->run();
        $output = trim($process->getOutput());

        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            if (preg_match('/^(\d+)\s+(\d+)\s+(.+)$/', $line, $matches)) {
                $pid = (int) $matches[1];
                $memoryKb = (int) $matches[2];
                $memoryMb = round($memoryKb / 1024, 1);
                $command = trim($matches[3]);

                $processes[] = [
                    'pid' => $pid,
                    'command' => $command,
                    'memory' => $memoryMb.' MB',
                ];
            }
        }

        return ['processes' => $processes];
    }

    /**
     * Kill a specific process by PID.
     */
    public function killProcess(int $pid): array
    {
        if (! $this->isProcessRunning($pid)) {
            return ['success' => false, 'message' => "Process {$pid} is not running."];
        }

        if ($this->isWindows()) {
            $process = Process::fromShellCommandline("taskkill /F /PID $pid /T");
            $process->run();
            $success = $process->getExitCode() === 0;
        } elseif (function_exists('posix_kill')) {
            $success = @posix_kill($pid, 9);
        } else {
            $process = Process::fromShellCommandline("kill -9 $pid 2>/dev/null");
            $process->run();
            $success = $process->getExitCode() === 0;
        }

        // If we killed the tracked bot PID, clean up state files
        $trackedPid = $this->readPid();
        if ($trackedPid === $pid) {
            $this->clearPidFile();
            $this->clearMetaFile();
        }

        return [
            'success' => $success,
            'message' => $success ? "Process {$pid} killed." : "Failed to kill process {$pid}.",
        ];
    }

    public function getManualCommands(): array
    {
        $mvn = $this->getMavenExecutable();

        if ($this->isWindows()) {
            $mvnCmd = (! str_ends_with(strtolower($mvn), '.cmd') && ! str_ends_with(strtolower($mvn), '.bat') && $mvn === 'mvn') ? 'mvn.cmd' : $mvn;
            $cwdWin = str_replace('/', '\\', $this->cwd);
            $logWin = str_replace('/', '\\', $this->logFile);
            $jarWin = str_replace('/', '\\', $this->jarPath);

            $startCmd = $this->useMavenExec
                ? "cd /D \"{$cwdWin}\" && start /B \"\" \"{$mvnCmd}\" exec:java \"-Dexec.mainClass={$this->mainClass}\" >> \"{$logWin}\" 2>&1"
                : "cd /D \"{$cwdWin}\" && start /B \"\" java -jar \"{$jarWin}\" >> \"{$logWin}\" 2>&1";

            return [
                'compile' => "cd /D \"{$cwdWin}\" && \"{$mvnCmd}\" compile",
                'start_bot' => $startCmd,
                'stop_bot' => 'for /f %i in ('.str_replace('/', '\\', $this->pidFile).') do taskkill /PID %i',
                'release_threads' => 'for /f %i in ('.str_replace('/', '\\', $this->pidFile).') do taskkill /PID %i',
                'list_processes' => 'tasklist /FI "IMAGENAME eq java.exe"',
                'kill_all' => 'taskkill /F /IM java.exe /T',
                'clear_pid_files' => 'del '.escapeshellarg($this->pidFile).' '.escapeshellarg($this->metaFile),
            ];
        }

        $javaOpts = env('JAVA_OPTS', '');
        $javaOptsStr = $javaOpts ? $javaOpts.' ' : '';
        $mavenOptsStr = $javaOpts ? 'MAVEN_OPTS='.escapeshellarg($javaOpts).' ' : '';

        $compileCmd = 'cd '.escapeshellarg($this->cwd).' && '.escapeshellarg($mvn).' compile';
        $startCmd = $this->useMavenExec
            ? 'cd '.escapeshellarg($this->cwd).' && '.$mavenOptsStr.'nohup '.$mvn.' exec:java -Dexec.mainClass='.escapeshellarg($this->mainClass).' >> '.escapeshellarg($this->logFile).' 2>&1 & echo $!'
            : 'cd '.escapeshellarg($this->cwd).' && nohup '.$javaOptsStr.'java -jar '.escapeshellarg($this->jarPath).' >> '.escapeshellarg($this->logFile).' 2>&1 & echo $!';
        $stopCmd = 'kill -15 $(cat '.escapeshellarg($this->pidFile).')';

        return [
            'compile' => $compileCmd,
            'start_bot' => $startCmd,
            'stop_bot' => $stopCmd,
            'release_threads' => $stopCmd,
            'list_processes' => "pgrep -af '".addslashes($this->processPattern)."'",
            'kill_all' => "pkill -9 -f '".addslashes($this->processPattern)."'",
            'clear_pid_files' => 'rm -f '.escapeshellarg($this->pidFile).' '.escapeshellarg($this->metaFile),
        ];
    }
}
