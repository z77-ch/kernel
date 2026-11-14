<?php
/**
 * Base knowledge of the KI-Assistent (ADR-055, kibot.md «help authoring»): how every standard
 * list of the backend is operated — the kernel's list partials (`partials/listHead`,
 * `listFindPop`, `listReset`, `pager`, `rowHandle`, `upload`; listing.md). Attached to no
 * screen; the help index finds it under `Backend/Guide/`. Static text only.
 */
?>
<h3>Listen bedienen: suchen, sortieren, blättern</h3>
<p>So suchst du in einer Liste nach einem Namen, Datum oder Betrag, sortierst nach einer Spalte, setzt die Suche zurück und blätterst. Gilt für alle Listen im Backend (Rechnungen, Aufträge, Belege, Kontakte …).</p>

<h4>In einer Spalte suchen</h4>
<p>Neben dem Spaltentitel steht eine Lupe. Ein Klick öffnet ein kleines Fenster «… suchen»: Suchbegriff eingeben, Enter oder «Suchen». Die Liste zeigt dann nur noch die passenden Zeilen; der Suchbegriff steht neben dem Spaltentitel. Esc oder ein Klick daneben schliesst das Fenster ohne Suche.</p>
<ul>
    <li>Suchst du in mehreren Spalten, gelten alle Bedingungen zusammen.</li>
    <li>Text: findet jede Zeile, die den Begriff enthält.</li>
    <li>Datum: «15.11.2026» (ein Tag), «11.2026» (ein Monat) oder «2026» (ein Jahr).</li>
    <li>Betrag: «1'250.50», «1250,5» oder «-30» — der Betrag muss genau stimmen.</li>
    <li>Nummer: nur Ziffern.</li>
    <li>Kann das System den Begriff nicht lesen (z. B. «31.02.2026»), ist die Lupe rot markiert und die Suche wird ignoriert — nichts wird geraten.</li>
</ul>
<p>Bei manchen Listen öffnet auch ein Klick auf einen Wert in der Zeile die Suche dieser Spalte.</p>

<h4>Sortieren</h4>
<p>Ein Klick auf einen Spaltentitel mit ↕ sortiert nach dieser Spalte; ein zweiter Klick dreht die Reihenfolge um. ↑ oder ↓ zeigt, wonach gerade sortiert ist.</p>

<h4>Suche und Sortierung zurücksetzen</h4>
<p>Sobald eine Suche oder eine andere Sortierung aktiv ist, erscheint vor dem Listentitel «Zurücksetzen». Es entfernt alle Spaltensuchen und stellt die normale Sortierung wieder her. Ein gewählter Reiter (z. B. «Offen») bleibt.</p>

<h4>Blättern</h4>
<p>Lange Listen sind in Seiten aufgeteilt. Unter der Liste: ‹ und › für die vorherige und nächste Seite, dazwischen die Seitenzahlen, daneben die Anzahl Zeilen und «Seite x von y». Eine Suche beginnt immer wieder auf Seite 1. Gedruckt wird die Seite, die du siehst.</p>

<h4>Positionen verschieben und einrücken</h4>
<p>In Positionstabellen (Auftrag, Rechnung) hat jede Zeile vorne einen Griff ⋮⋮: Zeile mit der Maus ziehen oder mit Alt+↑ / Alt+↓ verschieben. Das Häkchen ⇥ rückt eine Position unter die Position darüber ein.</p>

<h4>Dateien hochladen</h4>
<p>Wo Dateien hochgeladen werden, kannst du sie in das Feld ziehen oder auswählen und «Hochladen» klicken. Jede Datei zeigt ihren Fortschritt und ihr eigenes Ergebnis; eine abgelehnte Datei bleibt mit dem Grund stehen, die anderen werden trotzdem übernommen.</p>
