<!-- phil-dev source-sha256=cbac51779c043a3fafa7aa8c07ef2fbf6c6f856ba528bed62759615471499f5e -->
# Points d'extension

Ce qu'une application implémente, remplace, configure ou utilise. Les ports et leurs invariants sont ceux de
`phil/standard-webhooks`.

## Ports

Chacun est un alias que l'application fait pointer vers un service à elle — voyez
[Configuration](configuration.md#les-clés-qui-changent).

| Port | Fourni | Ce que fait celui de l'application |
|---|---|---|
| `Phil\StandardWebhooks\VerificationKeyProvider` | `FixedKeys`, les `verification_keys` configurées | Des clés qu'un expéditeur publie et renouvelle sans déploiement : le récepteur les recharge une fois avant de refuser une livraison ; `keys()` ne va jamais rien chercher |
| `Phil\StandardWebhooks\ProcessedWebhookRepository` | `DbalProcessedWebhooks`, sur `connection` | Garde les identifiants traités ailleurs ; `claim()` reste atomique dans la transaction de traitement, pour qu'une livraison soit traitée une seule fois |

## Configuration

| Option | Ce qu'elle étend |
|---|---|
| `signing_keys` | Les clés avec lesquelles l'application signe, la première étant la clé courante ; les autres signent à côté pendant une rotation |
| `verification_keys` | Les clés avec lesquelles les livraisons sont vérifiées |
| `tolerance`, `audience` | L'écart permis entre un horodatage et l'horloge, et le nom du récepteur |
| `connection` | Là où les identifiants traités sont gardés ; `null` pour un stockage propre à l'application |
| `allow_private_networks`, `allow_plain_http` | Si les livraisons peuvent aller vers des adresses privées et en HTTP simple — pour des récepteurs auxquels l'application elle-même fait confiance |

## Services

| Service | Comment une application l'utilise |
|---|---|
| Un argument de contrôleur typé `VerifiedWebhook` | La livraison, vérifiée avant que le contrôleur ne s'exécute — voyez [Réception](receiving.md) |
| `Phil\StandardWebhooksBundle\Infrastructure\Http\WebhookSender` | Envoie une livraison signée par `http_client` ; public — voyez [Envoi](sending.md) |
| `Phil\StandardWebhooks\WebhookReceiver` | Vérifie une livraison que l'application lit elle-même ; public |
