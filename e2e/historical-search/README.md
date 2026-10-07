# Historische zelfstandige zoekschermtests

Deze twee ongewijzigde specs horen bij SEARCH-UI-01A/01B en de toenmalige
bibliographic-search.js-/REST-envelope. SEARCH-CONTRACT-01 en SEARCH-EXEC-01
vervangen het gemounte zelfstandige zoekscherm; de oude schermen zijn niet
meer bereikbaar via /zoeken/.

Daarom staan deze specs buiten de actieve Playwright testDir. De actieve
vervanging is ../specs/search-exec-01.spec.mjs: tekst/auteur/ISBN, aanwezigheid,
Boekoverzicht/uitgave/exemplaar, afzonderlijke paging/retry, focus, mobiel en
veilige terugkeer. Oude bibliographic-search/discovery-contracten blijven
in hun Node-tests getest voor bestaande consumenten. Dit archiveert alleen
vervallen schermaannames en verklaart geen andere brede suitefout opgelost.
