# Woo2Etos

Woo2Etos è un plugin per WooCommerce che aggrega tutti gli attributi che contengono "taglia" in un singolo attributo globale chiamato **Taglia** (`taglia-w2e`).

## Funzionalità principali
- Crea e mantiene sincronizzato l'attributo aggregatore per l'integrazione con il gestionale Etos.
- Nasconde l'attributo `taglia-w2e` dall'editor prodotto e dalla scheda "Informazioni aggiuntive".
- Utility per garantire l'esistenza dell'attributo e per rimuovere i termini vuoti.
- Possibilità di avviare la sincronizzazione manualmente o tramite hook automatici e cron.

## Requisiti
- WordPress 6.0 o superiore.
- PHP 7.4 o superiore.
- WooCommerce attivo.

## Installazione
1. Copia la cartella del plugin in `wp-content/plugins/` oppure installalo tramite zip.
2. Attiva il plugin da **Plugin** > **Aggiungi nuovo**.
3. Vai su **WooCommerce > Woo2Etos** per configurare i settaggi e avviare la sincronizzazione.

## Licenza
Questo plugin è distribuito sotto la licenza [GPLv2 o successiva](https://www.gnu.org/licenses/gpl-2.0.html).

## Contribuire
Le issue e le pull request sono benvenute tramite GitHub. Prima di inviare una modifica, esegui i linters PHP:

```bash
php -l woo2etos.php
php -l includes/class-woo2etos.php
```

