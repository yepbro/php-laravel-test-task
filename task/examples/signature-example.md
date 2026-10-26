# Пример расчёта подписи

| | |
|---|---|
| Секрет | `whsec_test_5f1d8c2a9b` |
| `X-Webhook-Timestamp` | `1792577149` |
| Тело запроса | содержимое файла [`signature-example-body.json`](signature-example-body.json), 765 байт, без перевода строки в конце |

Подписываемая строка — значение `X-Webhook-Timestamp`, точка и тело запроса:

```text
1792577149.{"id":"evt_01JAXM4Q9T6V2K8R3N5W7Y0ZBC","type":"order.paid",...}
```

Ожидаемый заголовок:

```text
X-Webhook-Signature: sha256=4d4f2d50f98596ab503d29dc96db6fda247e3c482bff130bd97353564cb163f7
```
