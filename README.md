# Ecobank payout — reference implementation

A dependency-free PHP implementation of the three calls needed to pay a beneficiary
through **Ecobank's Local Bank Payment API** (the corporate API behind
`apimuat-developer.ecobank.com`): obtain a token, order a domestic transfer, ask what
became of it.

It exists to be **read and ported**, not only to be run. Everything that is not obvious
from Ecobank's own documentation — and there is a lot of it — is written down here or in
a docblock next to the code that depends on it.

---

## Table of contents

1. [What this is, and what it is not](#1-what-this-is-and-what-it-is-not)
2. [Credentials: two independent gates](#2-credentials-two-independent-gates)
3. [The signature algorithm](#3-the-signature-algorithm)
4. [Test vectors — start a port here](#4-test-vectors--start-a-port-here)
5. [The three calls](#5-the-three-calls)
6. [Reading a response](#6-reading-a-response)
7. [Behavioural rules that are not in the schema](#7-behavioural-rules-that-are-not-in-the-schema)
8. [Using this package in PHP](#8-using-this-package-in-php)
9. [The sandbox does not validate signatures](#9-the-sandbox-does-not-validate-signatures)
10. [Known defects in Ecobank's documentation](#10-known-defects-in-ecobanks-documentation)
11. [Open questions](#11-open-questions)
12. [Porting checklist](#12-porting-checklist)

---

## 1. What this is, and what it is not

**It is** the protocol: signatures, payload shapes, transport rules, error semantics.

**It is not** a payout system. There is no notion of a merchant, a wallet, a ledger, a
retry queue or a reconciliation job. Those belong to the application that uses this, and
they encode that application's business rules rather than Ecobank's protocol. The class
that glues this to a domain is about forty lines and is not worth sharing; the ninety
lines of signature and payload rules are.

Extracted from Izy (a merchant payment proxy in Benin) on 16 Sep 2026. It is a **one-way
snapshot**: Izy keeps its own copy, so this repository will not automatically receive
later fixes. Check the date before trusting a detail against a live integration.

### Layout

| Path | Role |
| --- | --- |
| `src/Signature.php` | The two SHA-512 signatures. Pure, no I/O. The heart of a port. |
| `src/Payload.php` | Builds the three request bodies. Pure, no I/O. |
| `src/Configuration.php` | Credentials and gateway address, with a completeness check. |
| `src/Client.php` | The three calls, token caching, the 401 retry, verdict handling. |
| `src/Transport.php` | One-method seam over HTTP. Implement it with your own client. |
| `src/CurlTransport.php` | A default so the package runs alone. |
| `src/TokenStore.php` | Seam over your cache. `InMemoryTokenStore` is the default. |
| `src/ServiceCode.php`, `src/RequestType.php` | The constant values, with what each is for. |
| `vectors/signature.json` | Language-agnostic test vectors. **Port against these.** |
| `tests/` | PHPUnit tests, including one that runs every vector. |

---

## 2. Credentials: two independent gates

Every call must pass **two** unrelated checks. Their refusals look alike, which is what
makes the first day confusing.

| | Where it goes | Where it comes from |
| --- | --- | --- |
| **Subscription key** | `Ocp-Apim-Subscription-Key` header | Developer portal → Profile → your subscription → primary or secondary key |
| **Bearer token** | `Authorization: Bearer …` | The token endpoint, using the values below |

And the values the token endpoint needs, all issued at **onboarding** and none of them
visible in the portal:

| Value | Travels? | Role |
| --- | --- | --- |
| `clientId` | yes, in the header | identifies the partner |
| `sourceCode` | yes, in the header | identifies the calling channel |
| `affiliateCode` | yes, in the header | Ecobank country code, e.g. `EGH` for Ghana |
| `publicKey` | yes, in the token body | issued at onboarding |
| **secret key** | **never** | hashed into both signatures |
| `ipAddress` | yes, in the header | your address as the bank sees it |

Two consequences worth stating plainly, because both cost time:

- **`publicKey` and the subscription key are different things.** The names suggest a
  pair; they belong to different systems. The subscription key is the API-management
  gate, the publicKey is part of the partner's identity.
- **`ipAddress` is signed**, so it must be identical between the token request and the
  payment it authorises. Configure it; do not detect it. A container behind a proxy
  reports an internal address that changes on restart, and the mismatch surfaces only as
  a refused signature.

---

## 3. The signature algorithm

**SHA-512, over UTF-8 bytes, lower-case hex, 128 characters.**

It is **not an HMAC**. The secret key is plainly concatenated at the end of the string,
then the whole string is hashed. A port that reaches for its language's HMAC API will
produce a different digest and get no useful error back.

Two signatures travel with every call, and they cover different things.

### requestToken

```
tokenString  = clientId + affiliateCode + sourceCode + requestId + requestType + ipAddress + secretKey
requestToken = SHA-512(tokenString)
```

### secureHash

```
hashString   = clientId + affiliateCode + sourceCode + requestId + requestType + ipAddress
             + requestToken
             + <the endpoint's own fields, in its documented order>
             + secretKey
secureHash   = SHA-512(hashString)
```

The endpoint-specific tail:

| Call | Fields appended after `requestToken` |
| --- | --- |
| Token request | `publicKey`, `serviceCode` |
| Domestic transfer | `receiverAccountNo`, `amountString`, `currency`, `description` |
| Transaction status | `transactionReference` |

### The three traps

**1. `requestToken` feeds `secureHash`.** Compute the first, then concatenate it into the
second. Computing both in parallel from the same inputs produces two well-formed digests
that are rejected together, with nothing saying which is wrong.

**2. The hashed order is not the JSON order.** The payload begins `affiliateCode`,
`clientId`; the hash begins `clientId`, `affiliateCode`. Concatenating the document
top-down — which is exactly what the *previous* Ecobank API required — silently produces
the wrong digest. `vectors/signature.json` records the wrong value explicitly so a port
can assert it does *not* produce it.

**3. The amount is hashed as text.** The documentation calls it `amountString`. That text
must match what lands in the JSON body. PHP's `json_encode(50000.0)` writes `50000`, and
`Signature::amountString()` agrees — but several languages render a float as `50000.0`,
and then the bank hashes one string while you hashed another. Check this equivalence in
your own language before trusting it.

---

## 4. Test vectors — start a port here

`vectors/signature.json` is the contract. It is a data file, not test code, so an
implementation in any language can load it and check itself.

```json
{
  "secretKey": "test-secret-key-not-a-real-one",
  "cases": [
    {
      "name": "requestToken for a domestic transfer",
      "header": { "clientId": "CL001", "affiliateCode": "EGH", "...": "..." },
      "concatenated": "CL001EGHCORP_CIB_MOBILE…test-secret-key-not-a-real-one",
      "expected": "<128 hex characters>"
    }
  ]
}
```

Every case that produces a digest carries the **exact concatenated string** alongside it,
so a mismatch tells you which half is wrong: if your concatenation matches and your digest
does not, your hashing is wrong; if the concatenation itself differs, your field ordering
is. Two further cases have no digest — the ordering trap and the `amountString` samples.

The check a port should write first, in pseudocode:

```
ORDER   = [clientId, affiliateCode, sourceCode, requestId, requestType, ipAddress]
vectors = parse("vectors/signature.json")
secret  = vectors.secretKey

for case in vectors.cases, where case.expected exists:

    # Pass 1 — hashing alone. The string is given, so only SHA-512 is under test.
    assert sha512_hex(case.concatenated) == case.expected

    # Pass 2 — your own concatenation. This is what proves the field ordering.
    parts = [ case.header[key] for key in ORDER ]
    if case.requestToken exists:
        parts.append(case.requestToken)
    parts.append(each of case.fields, in their given order)
    assert join(parts) + secret == case.concatenated

# The trap — your implementation must never produce this value.
trap = the case carrying `wrongValue`
assert sha512_hex(trap.wrongConcatenation) == trap.wrongValue
```

Pass 1 checks your hashing. Pass 2 checks your ordering, and is the one that matters —
a port can hash perfectly and still concatenate in the document's order. Write both.

---

## 5. The three calls

All are `POST`, all take and return JSON. Base URL for the UAT gateway:

```
https://apimuat-gateway.ecobank.com
```

Headers on every call:

```
Content-Type: application/json
Accept: application/json
Cache-Control: no-cache
Ocp-Apim-Subscription-Key: <subscription key>
Authorization: Bearer <access token>        ← all but the token call itself
```

### 5.1 Token — `POST /corp-auth/api/v2/integration/auth/app/token`

```json
{
  "headerRequest": {
    "affiliateCode": "EGH",
    "clientId": "CL001",
    "sourceCode": "CORP_CIB_MOBILE",
    "requestId": "IZYABCDEFGH1234",
    "ipAddress": "192.168.1.1",
    "requestType": "GET_API_TOKEN",
    "requestToken": "<128 hex>"
  },
  "publicKey": "corp_public_…",
  "serviceCode": "DOMESTIC",
  "secureHash": "<128 hex>"
}
```

Response `data`: `access_token`, `token_type` (`Bearer`), `refresh_token`, `expires_in`.

`serviceCode` decides what the token opens: `DOMESTIC`, `INTERBANK`, `ACCOUNT_TRANSFER`,
`BILLPAYMENT`, `TOKEN`, `DIRECTDEBIT`, `REMITTANCE`. A payout uses `DOMESTIC`. A token
minted for one service is refused by the others, so **cache per service, never globally**.

### 5.2 Domestic transfer — `POST /corp-payment/api/v2/payment/domestic`

> *Transfer from partner's pool account to other Ecobank accounts within a country.*

```json
{
  "headerRequest": { "...": "...", "requestType": "DOMESTIC_TRANSFER", "requestToken": "<128 hex>" },
  "secureHash": "<128 hex>",
  "receiverAccountNo": "1441002006858",
  "amount": 10,
  "currency": "GHS",
  "description": "Payout 42"
}
```

Response `data`: `transactionReference` (the bank's), `externalRefNo` (your `requestId`,
echoed back).

**The debited account is named nowhere.** It is the pool account attached to your
`clientId`. A port looking for a source-account field is looking at the previous API, or
at *Account Transfer*, which is a different endpoint.

### 5.3 Transaction status — `POST /corp-payment/api/v2/integration/payment/status`

```json
{
  "headerRequest": { "...": "...", "requestType": "TRANSACTION_STATUS", "requestToken": "<128 hex>" },
  "secureHash": "<128 hex>",
  "transactionReference": "CORPGH242880000361"
}
```

Queried by the reference **the bank returned**, not by your `requestId`.

---

## 6. Reading a response

```json
{
  "headerResponse": {
    "affiliateCode": "EGH",
    "clientId": "CL001",
    "sourceCode": "CORP_MOBILE",
    "requestId": "REQ12345494890",
    "responseCode": "000",
    "responseMessage": "SUCCESS",
    "responseDesc": "Payment Success"
  },
  "data": { "transactionReference": "CORPGH242880000361", "externalRefNo": "IZYABCDEFGH1234" }
}
```

**The verdict is `headerResponse.responseCode`, not the HTTP status.** This API answers
`200` to refused requests. Reading the status alone books a failed transfer as sent.

| Code | Meaning |
| --- | --- |
| `000` | Success — the only one |
| `001` | Generic processing failure |
| `400` | Malformed request; `responseDesc` names the field |
| `401` | Authentication failed — **this is where a wrong signature lands** |
| `404` | Resource not found |
| `999` | Unexpected server error |

A wrong signature returns `responseDesc: "Authentication failed. Invalid Request Token"`.
It does not distinguish `requestToken` from `secureHash`, which is why the vectors matter.

---

## 7. Behavioural rules that are not in the schema

These are the ones that cost money if a port drops them.

**An acknowledgement is not a confirmation.** A successful transfer call means the order
was accepted, not that the money moved. Do not mark a payout as sent on it. Poll
`transactionStatus` and let that decide.

**Mint the `requestId` before the call and store it.** If the answer is lost, retry with
the *same* one: the bank recognises the same order. A fresh one looks like a second
transfer, and the first is already on its way.

**Keep the bank's `transactionReference` too.** Status is queried by it. Keeping only
your `requestId` leaves a transfer whose fate cannot be asked about. Make sure the column
is wide enough — `CORPGH242880000361` is 18 characters, and a database that truncates
silently leaves a reference that will never match.

**A transport failure is not a refusal.** A timeout or a dropped connection says nothing
about whether the order was executed. Treat it as unknown, and resolve it with a status
query using the stored reference — never by re-sending with a new `requestId`.

**Treat anything that is not an explicit failure as pending.** An unknown reference and a
transfer in flight look the same. Calling either a failure refunds a merchant whose money
is on its way.

**Tokens expire in minutes.** The documentation says five; the sandbox agrees. Cache well
inside that (this implementation uses 180 seconds), drop the cached token on a `401`, and
retry exactly once. Looping would spin forever on a revoked credential.

---

## 8. Using this package in PHP

```php
use Izy\EcobankPayout\{Client, Configuration};

$client = new Client(new Configuration(
    baseUrl:         'https://apimuat-gateway.ecobank.com',
    subscriptionKey: getenv('ECOBANK_SUBSCRIPTION_KEY'),
    clientId:        'CL001',
    affiliateCode:   'EGH',
    sourceCode:      'CORP_CIB_MOBILE',
    publicKey:       getenv('ECOBANK_PUBLIC_KEY'),
    secretKey:       getenv('ECOBANK_SECRET_KEY'),
    ipAddress:       '192.168.1.1',
    currency:        'GHS',
));

$requestId = Izy\EcobankPayout\Payload::newRequestId();
// store $requestId against your own transaction here, before the call

$data      = $client->domesticTransfer('1441002006858', 10.0, 'Payout 42', $requestId);
$reference = $data['transactionReference'];
// store $reference too — status is queried by it

$status = $client->transactionStatus($reference);
```

To inspect a payload without sending it — which is what an acceptance document needs:

```php
$body = $client->payload()->domesticTransfer('1441002006858', 10.0, 'Payout 42');
echo json_encode($body, JSON_PRETTY_PRINT);
```

Plug in your own HTTP client and cache by implementing `Transport` and `TokenStore`, and
passing them to the `Client` constructor.

### Running the tests

```bash
phpunit --configuration phpunit.xml
```

No `composer install` is needed: the package has no runtime dependencies and
`tests/bootstrap.php` carries a four-line autoloader.

---

## 9. The sandbox does not validate signatures

Measured on 13 Sep 2026 against `apimuat-gateway.ecobank.com`: a payload whose
`requestId` was changed **without recomputing either hash** was answered
`responseCode: "000"`, `SUCCESS`, with a fresh token.

The consequence for a port is the whole reason `vectors/signature.json` exists: **you can
have a completely wrong `secureHash` and a green end-to-end run.** The sandbox will tell
you that your transport, headers and payload shape are right. It will not tell you your
signature is wrong, and production will.

Check against the vectors, not against a 200.

---

## 10. Known defects in Ecobank's documentation

Recorded 13–16 Sep 2026, so they may be fixed by the time you read this. Each one cost
time to find.

- **`documentation/service-code` and `documentation/request-type` are served empty.** The
  second would list every `requestType`. The three used here were read off the operation
  pages instead.
- **The authentication page's "Base URL" contradicts its own operation URL.** The banner
  says `https://artxuat.ecobank.com/corp-api/services/api/v2/integration/auth/`; the
  operation below it says `https://apimuat-gateway.ecobank.com/corp-auth/…`. The
  operation URL is the one that works.
- **`amount` is declared optional** (`required: false`) on the domestic transfer schema.
  Treat that as an error in their specification rather than as permission.
- **The Bulk Account Transfer formula ends its `hashString` with `public_key`** where
  every other endpoint ends with `secret_key`. Probably a typo in their documentation;
  irrelevant unless you implement bulk.

---

## 11. Open questions

Unresolved at extraction time. A port inherits them.

- **The `affiliateCode` for Benin.** Every example in the documentation is Ghanaian
  (`EGH`, `GHS`). `EBJ` is the plausible value; it has not been confirmed.
- **How `publicKey` relates to the portal's subscription keys.** The documentation names
  them separately and never says whether the primary key is the `publicKey`, the secret
  key, or neither.
- **The expected length of a beneficiary account number per country.** The examples are
  13 digits (`1441002006858`). A system modelling the BCEAO RIB holds 12 for the account
  component, and would truncate silently.
- **Whether the old platform is retired.** `developer.ecobank.com` now redirects to the
  new portal, but its endpoints still answered on 13 Sep 2026. No end-of-life date has
  been published.

---

## 12. Porting checklist

In the order that finds mistakes soonest.

1. **Signature first.** Load `vectors/signature.json` and reproduce every `expected`.
   Nothing else is worth writing until this passes.
2. **Assert the trap.** Check your implementation does *not* produce the recorded
   `wrongValue` — that is the document-order mistake, and it is the likeliest one.
3. **Check your float rendering.** `amountString(50000.0)` must equal what your JSON
   encoder writes for `50000.0`. Assert the two against each other, not against a
   literal.
4. **Build the payloads** and compare them field by field with section 5. Verify the
   signed amount equals the body's amount as text.
5. **Then the transport**: the two headers, the 401-retry-once, and the verdict read from
   `headerResponse.responseCode` rather than the HTTP status.
6. **Last, the behaviour of section 7.** These are the rules that decide whether a lost
   answer costs a double payment.

---

## Licence

MIT.
