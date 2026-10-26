# Processing

After an upload, the worker creates the renditions (image sizes, MP3s, MP4s). This page lists
uploads that did not finish.

- **Failed** — the worker tried three times and gave up, usually because the file is corrupt or
  in an unusual format. Check the worker log (`journalctl -u phantasos-worker`) for the reason.
- **Stuck** — uploaded more than 6 hours ago and still queued or processing. This normally means
  the worker was down. Make sure it is running, then retry.

**Retry** puts the item back on the queue to process the original again. Consumers see status
"In queue" until it finishes.
