<!-- phil-dev source-sha256=32b0192e12b67ed676986a00fe7638b678dccb8d496ad64068b4e4efa7ec9691 -->
# Configuration

```yaml
# config/packages/phil_standard_webhooks.yaml
phil_standard_webhooks:
    # The keys this application signs with: whsk_ (v1a) or whsec_ (v1). The first is the current one; during a
    # rotation, the others sign beside it, so that receivers that know only the old key keep accepting.
    signing_keys: ['%env(WEBHOOK_SIGNING_KEY)%']
    # The keys deliveries are verified with: whpk_ (v1a) or whsec_ (v1).
    verification_keys: ['%env(PARTNER_PUBLIC_KEY)%']
    # How many seconds a delivery's timestamp may be from the clock, either way. 300 when left out.
    tolerance: 300
    # This receiver's name: an envelope must give it as its "audience". null, the default, accepts any.
    audience: shop
    # The Doctrine connection that keeps the processed ids; null keeps them elsewhere. "default" when left out,
    # null without DoctrineBundle.
    connection: default
    # Whether deliveries may go to a loopback, private, link-local or reserved address. false when left out.
    allow_private_networks: false
    # Whether deliveries may go to an http URL rather than https only. false when left out.
    allow_plain_http: false
```

Chaque clé est facultative : une application qui ne fait que recevoir ne configure aucune clé de signature, une
application qui ne fait qu'envoyer aucune clé de vérification. **Les clés sont lues à leur première utilisation**, pas
à la construction du conteneur, pour qu'elles puissent venir de variables d'environnement ; une clé illisible fait
échouer la première livraison qui en a besoin. Les clés de vérification sont lues par la première requête dont le
contrôleur prend un argument `VerifiedWebhook`, et par aucune autre : une clé illisible fait répondre ce point de
terminaison de webhooks par une erreur serveur, jamais une autre page de l'application.

`allow_private_networks` et `allow_plain_http` protègent l'expéditeur contre la falsification de requête côté serveur
([sending.md](sending.md#des-url-de-récepteurs-données-par-dautres)). Ne les activez que pour des récepteurs auxquels
l'application elle-même fait confiance, comme un noyau de test à `http://app.localhost`.

| Refusé à la construction | Message |
|---|---|
| Une clé que le bundle ne connaît pas | `Unrecognized option "…" under "phil_standard_webhooks"` |
| `audience: ''` | `The audience is a name, or null to accept any` |
| `tolerance: 0` | `Should be greater than or equal to 1` |

## Générer des clés

```bash
bin/console phil:standard-webhooks:generate-key        # v1a: whsk_… for the sender, whpk_… for the receivers
bin/console phil:standard-webhooks:generate-key v1     # v1: one whsec_… shared by the sender and one receiver
```

La commande affiche les clés une seule fois et ne garde rien : placez le secret dans le magasin de secrets de
l'application.

## Les services

| Service | Ce que c'est |
|---|---|
| `Phil\StandardWebhooks\WebhookReceiver` | Public ; sur `VerificationKeyProvider`, la tolérance et l'audience |
| `Phil\StandardWebhooks\VerificationKeyProvider` | Un alias de `Phil\StandardWebhooks\FixedKeys`, les clés configurées |
| `Phil\StandardWebhooks\Signer`, `Phil\StandardWebhooks\Verifier` | Ceux de la bibliothèque |
| `Phil\StandardWebhooksBundle\Application\SigningKeys` | Les clés de signature configurées |
| `Phil\StandardWebhooksBundle\Infrastructure\Http\WebhookSender` | Public ; sur le service `http_client` |
| `Phil\StandardWebhooks\ProcessedWebhookRepository` | Public ; un alias de `DbalProcessedWebhooks`, avec une connexion |

## Les clés qui changent

Les clés configurées sont fixes. Pour des clés publiées par un expéditeur et récupérées — un document de clés, une
rotation sans déploiement —, écrivez un `VerificationKeyProvider` et créez un alias vers lui : le récepteur l'utilise
alors, et le recharge une fois avant de refuser une livraison. Les points d'extension de la bibliothèque
(`doc/extension-points.md` de `phil/standard-webhooks`) en montrent un.

```yaml
# config/services.yaml
services:
    App\Webhook\PublishedKeyProvider: ~
    Phil\StandardWebhooks\VerificationKeyProvider: '@App\Webhook\PublishedKeyProvider'
```

Il en va de même pour `Phil\StandardWebhooks\ProcessedWebhookRepository`, pour garder les identifiants traités
ailleurs.
