# Dashboard

- **Uploads** count media whose file arrived (tickets that were never used are not counted).
- **Files served** counts `requestMedia` downloads, from the audit log.
- **In processing queue** is the number of uploads waiting for, or being worked on by, the worker.
  If it keeps growing, the worker may be down.
- **Failed or stuck** links to the Processing page, where they can be retried.
