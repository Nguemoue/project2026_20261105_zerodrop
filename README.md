# zerodrop

Le zéro que PHP traite comme vide.  
The zero PHP treats as empty.

```text
array_filter(['ok', '0', 0, '', null, false])  →  ['ok']
'0' ?: 1                                        →  1
empty('0')                                      →  true
json_encode(array_filter(['', 'ok']))           →  {"1":"ok"}
```

`array_filter()` sans callback utilise `empty()`. Le stock `0`, le code promo `"0"` et le booléen `false` disparaissent. `?:` fait la même chose au moment du défaut : la quantité saisie `0` devient `1`. Et comme les clés sont conservées, le JSON n'est même plus un tableau.

`zerodrop` est un scanner **sans dépendance** qui lit du PHP et montre la ligne.

```bash
php zerodrop.php --prove
php zerodrop.php src/
```

```text
Preuve, sur cet interpréteur PHP :

  array_filter(['ok', '0', 0, '', null, false])
  → ["ok"]
    0, "0", false, "" et null sont jetés. Il ne reste que "ok".

  json_encode(array_filter(['', 'ok']))
  → {"1":"ok"}
    La clé 0 a sauté, donc ce n'est plus un tableau JSON.

  '0' ?: 'absent'   → "absent"
  empty('0')        → true
  '0' ?? 'absent'   → "0"
```

```text
zerodrop — le zéro que PHP traite comme vide

examples/buggy.php
  HIGH    filter-bare        10  $kept = array_filter($codes);
          array_filter() sans callback jette 0, "0", false, "" et null. Une quantité à zéro disparaît.
          → Garde le zéro : array_filter($rows, static fn ($v) => $v !== null && $v !== ''). array_values() si tu encodes en JSON.
  HIGH    collect-filter     12  $visible = $orders->filter();
          ->filter() sans callback fait le même tri que array_filter(). 0 et "0" disparaissent (Collection Laravel comprise).
          → ->filter(static fn ($v) => $v !== null && $v !== '')
  HIGH    elvis-zero         21  $fallback = $qty ?: 1;
          ?: remplace 0, "0", false et "". "0" ?: 1 vaut 1. La quantité saisie devient le défaut.
          → Pour seulement null : $qty ?? 1. Pour null ou "" mais pas "0" : ($qty === null || $qty === '') ? 1 : $qty
```

Code de sortie `1` dès qu'un finding est là. Branchable en CI.

---

## Pourquoi ce bug est réel

Ce n'est pas un coin de manuel. C'est le coupon `"0"`, la quantité `0`, et le payload JSON qui change de forme.

```php
$codes = ['SAVE10', '0', 0];
$kept = array_filter($codes);
// ['SAVE10']  — le code "0" et la quantité 0 n'existent plus

$qty = $request->input('qty'); // "0"
$qty = $qty ?: 1;               // 1

return response()->json(array_filter($rows));
// la première case vide fait basculer le JSON de [] vers {}
```

| Écriture | Ce qui arrive vraiment |
| --- | --- |
| `array_filter($rows)` | jette `0`, `"0"`, `0.0`, `false`, `""`, `null`, `[]` |
| `array_filter($rows, null)` | le même filtre, écrit en clair |
| `array_filter($rows, fn ($v) => $v)` | encore le test truthy : `0` et `"0"` sautent |
| `collect($rows)->filter()` | Laravel fait le même test |
| `$qty ?: 1` | remplace `0`, `"0"`, `false`, `""` par `1` |
| `empty($qty)` | `empty("0")`, `empty(0)` et `empty(0.0)` valent `true` |
| `"0.0" ?: 'absent'` | **ne** remplace **pas** : `"0.0"` est truthy. `empty("0.0")` vaut `false` |
| `$qty ?? 1` | ne remplace que `null`. Le zéro reste |

`examples/fixed.php` rejoue la liste et affiche `["ok","0",0,false]` puis `0`.

La version qui garde un vrai zéro :

```php
$kept = array_values(array_filter(
    $rows,
    static fn ($value) => $value !== null && $value !== ''
));

$qty = ($input === null || $input === '') ? 1 : $input;
```

`false` est conservé par ce callback. Pour le jeter aussi, sans jeter `0` :

```php
static fn ($value) => $value !== null && $value !== '' && $value !== false
```

## Installation

PHP **8.1+**. Rien à `composer require`.

```bash
git clone https://github.com/Nguemoue/project2026_20261105_zerodrop.git
cd project2026_20261105_zerodrop
php zerodrop.php --self-test
php zerodrop.php --lang=fr chemin/vers/src
```

## Usage

```bash
php zerodrop.php [options] <fichier-ou-dossier>...
```

| Option | Effet |
| --- | --- |
| `--lang=fr` ou `--lang=en` | Langue des messages. Défaut : `en`, ou `fr` si `LANG` commence par `fr`. |
| `--json` | Sortie JSON pour la CI |
| `--min=high` | Seuil : `high` (défaut), `medium`, `low` |
| `--ignore=regle,regle` | Tait des règles |
| `--no-color` | Pas d'ANSI |
| `--prove` | Montre le zéro jeté sur **ce** PHP |
| `--self-test` | Joue les fixtures du dépôt |

Une ligne qui contient le commentaire `zerodrop:ignore` est sautée.

Dossiers ignorés : `vendor/`, `node_modules/`, `.git/`. Extensions lues : `.php`, `.phtml`, `.inc`.

### Règles

| Règle | Niveau | Exemple |
| --- | --- | --- |
| `filter-bare` | high | `array_filter($rows)` |
| `collect-filter` | high | `->filter()` sans argument, Collection comprise |
| `filter-null` | high | `array_filter($rows, null)` |
| `filter-truthy` | high | `fn ($v) => $v`, `fn ($v) => (bool) $v`, `fn ($v) => !empty($v)` |
| `elvis-zero` | high | `$qty ?: 1`, y compris `$qty ? : 1` |
| `empty-zero` | medium | `empty($qty)`. Passe `--min=medium` |

`empty()` sur un tableau est souvent voulu. La règle est `medium` pour ne pas faire échouer la CI par défaut. `$a ? $b : $c`, `??` et `?->` ne sont pas l'opérateur `?:`.

Ce n'est pas PHPStan. Un callback dans une variable (`$keep`) n'est pas suivi. `strlen` comme callback est laissé, parce que `strlen("0")` vaut `1`.

## Pour partager

> `array_filter(['ok', '0', 0])` vaut **`['ok']`**.  
> `'0' ?: 1` vaut **`1`**. `empty('0')` vaut **`true`**.  
> `json_encode(array_filter(['', 'ok']))` vaut **`{"1":"ok"}`**, plus un tableau.  
> Scanner sans dépendance : `php zerodrop.php src/`

## Vérifier

```bash
php zerodrop.php --self-test
php examples/fixed.php
```

Le self-test exige `["ok"]`, `{"1":"ok"}`, `"absent"` et la liste qui garde le zéro sur la machine qui le lance, les règles de `examples/buggy.php`, le silence de `examples/safe.php`, de `examples/fixed.php` et de `zerodrop.php`, et que `examples/vendor/` ne soit pas lu.

## Licence

MIT.
