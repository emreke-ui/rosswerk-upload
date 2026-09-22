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

## Stand 22.09.2026 — zweiter Standort, weil rosswerk.de noch geparkt ist

`upload.rosswerk.de` ist auf dem Server eingerichtet, die Domain zeigt aber weiterhin
auf den Registrar (Porkbun-Parking), nicht auf uns. Der Dienst laeuft deshalb zusaetzlich
unter einer Domain, die wir selbst kontrollieren:

- Server: `nxtyou-dev-02` (104777), Site `upload-rosswerk.nxtyou.dev` (410088)
- Speicher: `/home/ploi/upload-rosswerk.nxtyou.dev/storage`, ausserhalb des Web-Verzeichnisses
- Aufraeumlauf: Cron `17 3 * * *` -> `cleanup.php`, loescht Stapel aelter als 90 Tage
- Pfade werden jetzt aus dem Skriptort abgeleitet, derselbe Stand laeuft auf beiden Domains

OFFEN: EIN DNS-Eintrag bei Cloudflare (nxtyou.dev liegt dort):
`upload-rosswerk` A 188.245.120.47 — zunaechst "DNS only", damit Ploi das
Let's-Encrypt-Zertifikat ausstellen kann. Danach kann Proxy an.

Ohne diesen Eintrag ist der Dienst nur ueber den Host-Header erreichbar. Gegen den
Server direkt getestet und bestanden:
- `GET /health` -> 200 `{"ok":true}`
- `POST /api/upload` mit PDF, Origin rosswerk.framer.website -> 200, Link zurueck
- fremde Herkunft -> 403 "Herkunft nicht erlaubt."
- .exe (Magic Bytes MZ) -> 422 "nur Bilder oder PDF."
- `GET /f/<id>` -> 200, Datei gelistet, Header `X-Robots-Tag: noindex, nofollow`

## Einbau in das Framer-Formular (fertig vorbereitet, aktiviert nach dem DNS-Eintrag)

Im Framer-Projekt unter Custom Code (bodyEnd) einsetzen. Das Feld haengt sich an das
bestehende Formular, laedt beim Auswaehlen hoch und schreibt den Abhol-Link in ein
verstecktes Textfeld, das Framer mit der Anfrage verschickt. Kein eigener Mailversand.
Der Baustein steht in `snippets/framer-upload.html`.
