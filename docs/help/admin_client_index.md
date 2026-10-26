# Clients & Keys

Each **client** is an application (ideally one per environment — e.g. *Training* and
*Training-QA*) with its own **API key**. A key can only read the media its own client uploaded.

- **New Client** creates the client and a key. The key is shown **once**; copy it then.
  Phantasos keeps only a hash, so a lost key cannot be recovered — rotate it instead.
- **Key last used** updates at most hourly. "Never" on an old client usually means it can be retired.
- Clients imported from the old system show the name they had there ("was …").
