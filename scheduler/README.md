# Windows Task Scheduler (auto pull from PACS)

`Diskom_Auto_Pull_PACS.xml` runs `diskom_auto_pull.php` every day at **12:00** and **23:59**.
It replaces the two separate exports (one per time) with a single task that has two triggers.

## Install (PowerShell as Administrator)

1. Edit the two paths in the XML if your setup differs (open it in Notepad, keep the encoding):
   - `Command`: path to `php.exe` (default `C:\xampp\php\php.exe`)
   - `Arguments` and `WorkingDirectory`: folder where this app is installed (default `C:\xampp\htdocs\radio`)
2. Import and test:

```powershell
schtasks /Create /TN "Diskom Auto Pull PACS" /XML ".\scheduler\Diskom_Auto_Pull_PACS.xml" /F
schtasks /Run /TN "Diskom Auto Pull PACS"
Get-Content C:\xampp\htdocs\radio\auto_pull_pacs.log -Tail 20
```

If you previously imported the two old tasks, remove them so the job does not run twice:

```powershell
schtasks /Delete /TN "Diskom Auto Pull PACS 1200" /F
schtasks /Delete /TN "Diskom Auto Pull PACS 2359" /F
```

## Notes

- Runs as `SYSTEM` (`S-1-5-18`) so it works without anyone logged in. If you prefer a dedicated
  low-privilege Windows account, change the principal in Task Scheduler and grant it
  write access to the app folder (log + lock file) and read access to `config.local.php`.
- `StartWhenAvailable` is on: if the PC was off at the scheduled time, the task runs once
  when it is back. `ExecutionTimeLimit` is 2 hours; a second run is skipped while one is active
  (the script also holds its own lock).
- The log `auto_pull_pacs.log` contains visit numbers. Keep it out of the web root's reach
  (the bundled `.htaccess` blocks `*.log` on Apache) or move it outside `htdocs`.
- The file is UTF-16 on purpose: that is the format Task Scheduler exports and imports.
