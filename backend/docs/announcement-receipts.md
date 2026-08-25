# Distribution interne des annonces

## Signification des reçus

Un enregistrement `announcement_receipts` signifie uniquement que l’annonce est disponible dans la boîte interne du compte ciblé. `available_at` est l’horodatage canonique de cette disponibilité et `read_at` indique une consultation explicite par ce compte.

Le champ historique `delivered_at` n’a jamais été relié à un transport mail, SMS ou push. Il représentait déjà la création interne du reçu. Il est conservé pour les anciens clients et alimenté avec la même valeur que `available_at`, mais il est déprécié et ne doit pas être interprété comme une preuve de livraison externe. La migration initialise `available_at` avec la valeur historique de `delivered_at` sans réinterpréter les données.

## Audience figée à la publication

La publication verrouille l’annonce et sélectionne les destinataires actifs dans une seule transaction. Les reçus sont insérés par une requête SQL ensembliste et la contrainte unique `(announcement_id, user_id)` demeure la protection contre les doublons.

- Une annonce nationale `all` cible tous les comptes actifs.
- Une annonce nationale `administrators` cible les superadmins et admins actifs.
- Une annonce nationale `faithful` cible les comptes actifs ayant au moins une fiche fidèle active.
- Une annonce locale `administrators` cible les admins actifs possédant un rattachement canonique `administrator` à la mosquée.
- Une annonce locale `faithful` cible les comptes actifs ayant une fiche fidèle active dans la mosquée.
- Une annonce locale `all` conserve la règle historique : union des deux audiences locales précédentes.

`mosques.admin_id`, un rôle seul, un rattachement seul, une permission directe ou une fonction de conseil ne constitue pas une autorité de distribution. Les comptes non actifs sont toujours exclus.

L’instantané ne change plus après publication : une nouvelle inscription, un nouveau rôle ou un nouveau rattachement n’ajoute pas rétroactivement de reçu, tandis qu’une perte ultérieure d’autorité ne supprime pas l’historique. Une seconde demande de publication est idempotente.

## Sécurité et exploitation

Un utilisateur ordinaire ne voit que les annonces publiées et actuellement visibles pour lesquelles son propre reçu existe. L’action de lecture recherche le reçu par l’annonce et l’utilisateur authentifié ; connaître un identifiant ne permet donc pas de lire le reçu d’un autre compte.

L’audit `announcement.distributed` contient uniquement le nombre total de reçus. Si l’insertion des reçus ou l’audit échoue, la transaction annule le statut publié, les reçus et les audits produits pendant la tentative.

Cette distribution est strictement interne. Elle ne lance aucun job, mail, SMS, push ou appel réseau et ne prétend aucune livraison ou lecture externe.
