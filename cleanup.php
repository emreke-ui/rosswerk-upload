<?php
// Taeglicher Aufraeumlauf: loescht Upload-Stapel, die aelter als 90 Tage sind.
// Pfad aus dem Ort des Skripts ableiten, damit es auf jeder Domain laeuft.
$store = __DIR__ . '/storage';
$grenze = time() - 90 * 86400;
$weg = 0;
foreach (glob($store . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
    if (filemtime($dir) > $grenze) continue;
    foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
    if (@rmdir($dir)) $weg++;
}
echo "Aufgeraeumt: $weg Stapel\n";
