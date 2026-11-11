<!-- phil-dev source-sha256=e349a50a6632afc276d5960dc03938b9c85a3e261278d3c3fcdf901320ea0a9d -->
# phil/standard-webhooks-bundle

**Des livraisons de webhooks vérifiées en arguments de contrôleur, et des webhooks signés envoyés depuis Symfony.**

phil/standard-webhooks-bundle intègre [Standard Webhooks](https://github.com/standard-webhooks/standard-webhooks)
1.0.0 à une application Symfony. Il lit les clés de signature et de vérification dans la configuration et construit
le récepteur. Un contrôleur reçoit la livraison vérifiée en argument. Une livraison mal formée reçoit un `400`, et une
livraison refusée un `401` avec sa raison. Le bundle envoie une tentative signée par le client HTTP de Symfony, garde
les identifiants traités dans une table d'une connexion Doctrine, et fournit une commande qui génère des clés.

Il s'adresse aux applications Symfony qui reçoivent des webhooks d'autres services ou envoient les leurs. Le format,
les signatures et les règles de vérification vivent dans `phil/standard-webhooks`, une bibliothèque PHP simple.
Utilisez cette bibliothèque seule hors de Symfony.

Le bundle se branche sur le noyau HTTP par un résolveur d'arguments de contrôleur. Le client HTTP, Doctrine DBAL et
le composant Console sont facultatifs, et chacun active l'expéditeur, le stockage ou la commande de clés. Les
identifiants traités sont revendiqués sans faire avorter la transaction de traitement : la déduplication et le
travail qu'elle protège partagent ainsi une même transaction.

## État

| Partie | État |
|---|---|
| Configuration : clés de signature et de vérification, tolérance, audience, connexion | Implémenté et testé |
| `VerifiedWebhookResolver` : l'argument de contrôleur `VerifiedWebhook` | Implémenté et testé |
| `WebhookSender` et `DeliveryFailed` | Implémenté et testé |
| `DbalProcessedWebhooks`, sa table et son écouteur de schéma | Implémenté et testé |
| `phil:standard-webhooks:generate-key` | Implémenté et testé |

## Documentation

| Document | Ce qu'il couvre |
|---|---|
| [Installation](doc/fr/installation.md) | Prérequis, enregistrement, la table. |
| [Configuration](doc/fr/configuration.md) | Chaque clé, la génération de clés, les services, les clés qui changent. |
| [Réception](doc/fr/receiving.md) | L'argument de contrôleur, les réponses, un point de terminaison complet. |
| [Envoi](doc/fr/sending.md) | `WebhookSender`, les nouvelles tentatives, les échecs, la protection sur les URL des récepteurs. |
| [Stockage](doc/fr/storage.md) | La table des identifiants traités, la revendication, la purge. |
| [Points d'extension](doc/fr/extension-points.md) | Ce qu'une application implémente, configure, remplace ou utilise. |
| [Tests](doc/fr/testing.md) | Comment le bundle est testé. |
| [Référence](doc/fr/reference.md) | Chaque type du bundle. |

Le protocole — les en-têtes, les clés, les signatures, l'ordre de vérification — est documenté avec
`phil/standard-webhooks`.

## Démarrage rapide

```yaml
phil_standard_webhooks:
    signing_keys: ['%env(WEBHOOK_SIGNING_KEY)%']
    verification_keys: ['%env(PARTNER_PUBLIC_KEY)%']
    audience: shop
```

```php
#[Route('/webhooks', methods: ['POST'])]
public function __invoke(VerifiedWebhook $webhook): Response
{
    // claim $webhook->id, process $webhook->envelope(), in one transaction
    return new Response(null, 204);
}

$id = $sender->send('https://partner.example/webhooks', $body);   // a WebhookSender
```

## Prérequis

PHP 8.5 ou plus récent ; `phil/standard-webhooks` 0.1, qui requiert `ext-sodium` ; `phil/url` 0.1.2, qui requiert les
extensions intl, mbstring et URI ; `psr/clock` 1.0 ; les composants dependency injection, config, HTTP foundation et
HTTP kernel de Symfony 8.2. Le client HTTP de Symfony, Doctrine DBAL et ORM avec DoctrineBundle, et Symfony Console sont
facultatifs.

## Contribuer

Les contributions sont les bienvenues sur le monodépôt PHIL : lisez le [guide de contribution](CONTRIBUTING.md) et le
[code de conduite](CODE_OF_CONDUCT.md). Signalez une vulnérabilité en privé, comme l'explique la
[politique de sécurité](SECURITY.md), jamais dans un ticket public.

## Licence

MIT, © Philippe Gamache, doing business as Le Gnome Archiviste; a Parler Haut Interagir Librement project. Voir
[LICENSE](LICENSE).
