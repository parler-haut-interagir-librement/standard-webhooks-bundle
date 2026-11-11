<!-- phil-dev source-sha256=8802277bff73ebed282202c5fbeff18167a1a6054b9f7692ab37187c89e6a71e -->
# Envoi

`WebhookSender::send()` fait une tentative d'une livraison : le corps, signé avec les clés de signature configurées à
l'heure courante, est envoyé en `POST` JSON qui ne suit aucune redirection et attend au plus dix secondes.

```php
use Phil\StandardWebhooksBundle\Infrastructure\Http\{DeliveryFailed, WebhookSender};

$body = json_encode(['type' => 'invoice.paid', 'timestamp' => $now->format(DATE_RFC3339), 'data' => ['id' => 'inv_1'], 'audience' => 'shop'], JSON_THROW_ON_ERROR);

try {
    $id = $sender->send('https://shop.example/webhooks', $body);
} catch (DeliveryFailed $failed) {
    // $failed->id: retry later with it; $failed->status: the answer, null when there was none
}
```

| Argument | Ce que c'est |
|---|---|
| `$url` | Le point de terminaison du récepteur |
| `$body` | Les octets exacts signés et envoyés |
| `$id` | `null` pour une nouvelle livraison, qui reçoit `WebhookId::generate()` ; **l'identifiant de la première tentative pour une nouvelle tentative**, pour que la déduplication du récepteur l'absorbe |
| `$headers` | Des en-têtes de plus ; `Content-Type` remplace `application/json`, les trois en-têtes de signature ne peuvent pas être remplacés |

Il retourne l'identifiant de la livraison ; il lance `DeliveryFailed` quand le récepteur répond hors de `2xx`, ne
répond pas, ou a une URL que l'expéditeur refuse. `DeliveryFailed::$retryable` dit lequel. Un `4xx` ou une URL
refusée est permanent : la même livraison échoue de la même façon. Un `5xx` ou une absence de réponse vaut une
nouvelle tentative, avec le même identifiant, selon le calendrier de l'application — une stratégie de nouvelles
tentatives de Messenger, une boîte d'envoi et un planificateur —, dans la fenêtre de déduplication du récepteur.

## Des URL de récepteurs données par d'autres

L'URL d'un récepteur est d'habitude enregistrée par un tiers — un abonné, une boutique partenaire. Utilisée telle
quelle, elle permettrait à ce tiers de faire envoyer par l'application des requêtes à l'intérieur de son propre
réseau : vers un point de terminaison de métadonnées d'infonuagique (`169.254.169.254`), vers un service interne, le
code d'état ou l'erreur de transport revenant comme réponse. L'expéditeur, par conséquent :

- n'envoie qu'à des URL `https` ; un autre schéma donne un `DeliveryFailed` sans nouvelle tentative possible,
  `the receiver's URL is not an https URL to a host name`. `allow_plain_http: true` accepte aussi `http` ;
- lit l'URL avec phil/url, comme un navigateur la lit, et l'envoie sous sa forme canonique : une URL avec un nom
  d'utilisateur ou un mot de passe, ou dont l'hôte est une adresse IP (avec des points, en un seul nombre comme
  `2130706433`, en IPv6) ou un seul nom comme `metadata`, est refusée de la même façon, avant toute requête, quelle
  que soit la configuration. Nommez un récepteur du réseau interne par un nom d'hôte, `hooks.internal.example`.
  `localhost` et les noms sous `.localhost` sont aussi refusés à moins de `allow_localhost: true`, pour un récepteur
  du poste de développement, qui a alors aussi besoin de `allow_private_networks: true` ;
- enveloppe le client HTTP dans le `NoPrivateNetworkHttpClient` de Symfony, qui refuse un hôte qui se résout en une
  adresse de bouclage, privée, de lien local ou réservée, avant que rien ne soit envoyé : un `DeliveryFailed` sans
  état, dont le message dit que l'hôte `is blocked`. `allow_private_networks: true` retire la protection ; il a
  besoin de `symfony/http-client`, que le service `http_client` apporte déjà.

Validez aussi une URL quand elle est enregistrée, et ne relayez jamais le message d'un `DeliveryFailed` au tiers qui
a enregistré l'URL : il nomme le code d'état ou l'erreur de transport, ce qu'une exploration interne lirait.

Sans le service `http_client` (`framework.http_client`), `send()` lance une `LogicException` ; sans clé de signature
aussi.
