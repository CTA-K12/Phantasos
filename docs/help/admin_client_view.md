# Client

**Rotate key** issues a new key and stops the old one immediately — the application is
refused until its configuration has the new key. Coordinate with its owner first, then
send the key through a secure channel.

**Deactivate** refuses every request with the key (HTTP 403) but keeps all media.
Reactivating restores the same key.

The **key fingerprint** is the first 12 characters of the key's SHA-256. To check which
client a key belongs to without revealing it: `printf %s "$KEY" | shasum -a 256 | cut -c1-12`.
