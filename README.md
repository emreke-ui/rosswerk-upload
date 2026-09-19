# ROSSWERK Upload-Dienst

Schlanker Datei-Endpunkt fuer das Ankauf-/Anfrageformular auf rosswerk.de (Framer).

- `POST /api/upload` — multipart, Feld `files[]`, optional `id` zum Nachreichen.
  Antwort: `{ok, id, link, dateien[], anzahl, hinweise[]}`
- `GET /f/<id>` — Abholseite fuer ROSSWERK (nicht indexierbar)
- `GET /health` — Gesundheitscheck

Grenzen: 10 Dateien, je 15 MB, nur Bilder (JPEG/PNG/WebP/HEIC) und PDF.
Pruefung per Magic Bytes, nicht per Dateiendung. Aufbewahrung 90 Tage,
danach automatische Loeschung (Cron + Zufallslauf beim Upload).

Der Abhol-Link wandert als verstecktes Formularfeld in die bestehende
Framer-Benachrichtigung — dadurch wird kein eigener Mailversand gebraucht.
