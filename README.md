# Ecobank payout

Three calls against Ecobank's Local Bank Payment API: get a token, send a transfer, ask
what became of it. No framework, no dependencies.

PHP reference implementation, written to be ported.

---

## 1. What you need

Issued at onboarding, one set per affiliate:

| Value | Example | Travels |
| --- | --- | --- |
| `clientId` | `FEDAPA262660565` | in the header |
| `affiliateCode` | `EBJ` (Benin), `EGH` (Ghana) | in the header |
| `sourceCode` | `FEDAPAY_API` | in the header |
| `ipAddress` | `192.168.1.1` | in the header, **and it is signed** |
| `publicKey` | `corp_pk_…` | in the token body |
| `secretKey` | `corp_sk_…` | **never sent** — hashed only |
| `currency` | `XOF` | in the transfer body |

Plus a **subscription key** from the developer portal, which is a separate thing from all
of the above. Two independent gates:

```
Ocp-Apim-Subscription-Key: <subscription key>     ← portal gate
Authorization: Bearer <token>                     ← partner gate
```

A wrong subscription key gives HTTP 401. A wrong signature gives HTTP 200 with an error
in the body. They look nothing alike, which helps.

`ipAddress` is part of the signed header but the bank does not verify it: measured
30 Sep 2026, a transfer declaring `NOT-AN-IP` was accepted, and the token call and the
payment it authorises may declare different addresses. Send the calling server's real
public address anyway — that is what Ecobank whitelists, at network level on the
connection rather than on this field. Configure it rather than detecting it: a container
behind a proxy reports an internal address that changes on restart.

### Base URL

```
https://apimuat-gateway.ecobank.com
```

`artxuat.ecobank.com/corp-api/services/...` is the same service at another address.
`apimuat-developer.ecobank.com` is the portal website, not an API — it answers 404.

---

## 2. Signature

SHA-512 over UTF-8, lower-case hex, 128 characters. **Not an HMAC** — the secret is
concatenated onto the end of the string and the whole thing is hashed.

```
HEADER = clientId + affiliateCode + sourceCode + requestId + requestType + ipAddress

requestToken = SHA512( HEADER + secretKey )
secureHash   = SHA512( HEADER + requestToken + <endpoint fields> + secretKey )
```

Endpoint fields, in this order:

| Call | Fields |
| --- | --- |
| Token | `publicKey`, `serviceCode` |
| Transfer | `receiverAccountNo`, `amount`, `currency`, `description` |
| Status | `transactionReference` |

Three things that produce a valid-looking wrong digest:

1. **`requestToken` goes inside `secureHash`.** Compute it first, then concatenate it.
2. **The hashed order is not the JSON order.** The body starts `affiliateCode`,
   `clientId`; the hash starts `clientId`, `affiliateCode`.
3. **The amount is hashed as text**, and that text must equal what your JSON encoder
   writes. `50000.0` must hash as `50000` if that is what lands in the body.

---

## 3. Token

```
POST /corp-auth/api/v2/integration/auth/app/token
```

**In**

```json
{
  "headerRequest": {
    "affiliateCode": "EBJ",
    "clientId": "FEDAPA262660565",
    "sourceCode": "FEDAPAY_API",
    "requestId": "IZYUHA4Z44WIGWY",
    "ipAddress": "192.168.1.1",
    "requestType": "GET_API_TOKEN",
    "requestToken": "<128 hex>"
  },
  "publicKey": "corp_pk_…",
  "serviceCode": "DOMESTIC",
  "secureHash": "<128 hex>"
}
```

**Out**

```json
{
  "headerResponse": { "responseCode": "000", "responseMessage": "SUCCESS" },
  "data": { "access_token": "…", "token_type": "Bearer", "expires_in": 1790783382000 }
}
```

`serviceCode` decides what the token opens: `DOMESTIC`, `INTERBANK`, `ACCOUNT_TRANSFER`,
`BILLPAYMENT`, `TOKEN`, `DIRECTDEBIT`, `REMITTANCE`. A payout uses `DOMESTIC`. A token for
one service is refused by the others, so **cache per service, not globally** — and per
identity, or two credential sets will share one token.

Lifetime is about five minutes. `expires_in` is a millisecond timestamp, not a duration.

---

## 4. Transfer

```
POST /corp-payment/api/v2/payment/domestic
```

**In**

```json
{
  "headerRequest": { "…": "…", "requestType": "DOMESTIC_TRANSFER", "requestToken": "<128 hex>" },
  "secureHash": "<128 hex>",
  "receiverAccountNo": "1441002006858",
  "amount": 1000,
  "currency": "XOF",
  "description": "Domestic Transfer"
}
```

**Out**

```json
{
  "headerResponse": { "responseCode": "000", "responseMessage": "SUCCESS" },
  "data": { "transactionReference": "CORPGH242880000361", "externalRefNo": "IZYUHA4Z44WIGWY" }
}
```

The debited account is the pool account attached to your `clientId`. It is not in the
payload — there is no source-account field to look for.

`description` must be 5 characters or more. `amount` must be greater than 0.

---

## 5. Status

```
POST /corp-payment/api/v2/integration/payment/status
```

**In**

```json
{
  "headerRequest": { "…": "…", "requestType": "TRANSACTION_STATUS", "requestToken": "<128 hex>" },
  "secureHash": "<128 hex>",
  "transactionReference": "CORPGH242880000361"
}
```

**Out** — `data.status` carries the verdict. An unknown reference answers
`responseDesc: "No record Found"`.

Queried by the reference **the bank returned**, not by your `requestId`.

---

## 6. Response codes

**The verdict is `headerResponse.responseCode`. The HTTP status is not.** This API answers
200 to refused requests.

| Code | Meaning |
| --- | --- |
| `000` | Success — the only one |
| `400` | Malformed request; `responseDesc` names the field |
| `401` | Authentication failed — where a wrong signature lands |
| `404` | Not found |
| `999` | Refused or failed; read `responseDesc` |

`999` covers several distinct situations, and the wording is what tells them apart:

| `responseDesc` | Means |
| --- | --- |
| `Invalid SecureHash or Request Token Provided` | your signature is wrong |
| `Invalid Key Provided` | wrong `publicKey` |
| `Client App not Configured for Service` | that `serviceCode` is not enabled for you |
| `No services has been setup for the client X` | wrong `clientId`/`affiliateCode` pair |
| `No app has been setup for the client with code X` | wrong `sourceCode` |
| `No DOMESTIC service has been setup … for the affiliate X` | **the currency is not enabled** — the message names the affiliate, but the currency is what it is refusing |
| `Error occured during processing` | the request was accepted and the bank failed internally |

---

## 7. Rules that cost money

- **An acknowledgement is not a confirmation.** A successful transfer means the order was
  accepted. Poll the status before you mark anything as paid.
- **Mint the `requestId` before the call and store it.** If the answer is lost, retry with
  the *same* one — the bank recognises the same order. A new one is a second transfer.
- **Store the bank's `transactionReference` too.** Status is queried by it. It can be 18
  characters; a column that truncates silently loses the transfer.
- **A timeout is not a refusal.** Resolve it with a status query, never by re-sending.
- **Anything that is not an explicit failure is pending.**

---

## 8. Verifying a port

`vectors/signature.json` carries, for each case, the header, the fields, the exact
concatenated string and the expected digest — plus one trap case recording the digest that
the document-order mistake produces, which a correct implementation must never output.

```
for each case with an expected digest:
    assert sha512_hex(case.concatenated) == case.expected         # your hashing
    assert join(your_parts(case)) + secret == case.concatenated   # your field order
    assert your_digest(case) == case.expected                     # the two together

assert your_digest(trap.header) != trap.wrongValue                # you did not read the JSON top-down
```

The second assertion is the one that matters: a port can hash correctly and still
concatenate in the document's order, and only this one separates the two faults.

**Do not trust an end-to-end success instead.** Signature checking is per client app: the
demo app in Ecobank's documentation issues a token no matter what you sign, while a real
onboarded app rejects a secret that is wrong by one character.

---

## 9. Using it

```php
use Izy\EcobankPayout\{Client, Configuration, Payload};

$client = new Client(new Configuration(
    baseUrl:         'https://apimuat-gateway.ecobank.com',
    subscriptionKey: getenv('ECOBANK_SUBSCRIPTION_KEY'),
    clientId:        'FEDAPA262660565',
    affiliateCode:   'EBJ',
    sourceCode:      'FEDAPAY_API',
    publicKey:       getenv('ECOBANK_PUBLIC_KEY'),
    secretKey:       getenv('ECOBANK_SECRET_KEY'),
    ipAddress:       '192.168.1.1',
    currency:        'XOF',
));

$requestId = Payload::newRequestId();   // store it before calling
$data      = $client->domesticTransfer('1441002006858', 1000.0, 'Domestic Transfer', $requestId);
$status    = $client->transactionStatus($data['transactionReference']);
```

Build the payload without sending it:

```php
$body = $client->payload()->domesticTransfer('1441002006858', 1000.0, 'Domestic Transfer');
```

Supply your own HTTP client or cache by implementing `Transport` and `TokenStore`.

### Tests

```bash
phpunit --configuration phpunit.xml
```

No `composer install` needed — there are no runtime dependencies.

---

## Files

| Path | Role |
| --- | --- |
| `src/Signature.php` | the two hashes |
| `src/Payload.php` | the three bodies |
| `src/Client.php` | the three calls, token cache, 401 retry |
| `src/Configuration.php` | credentials |
| `src/Transport.php`, `src/CurlTransport.php` | HTTP seam and default |
| `src/TokenStore.php`, `src/InMemoryTokenStore.php` | cache seam and default |
| `vectors/signature.json` | test vectors |

MIT.
