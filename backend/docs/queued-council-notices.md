# Convocations asynchrones du conseil

## Périmètre et destinataires

L’action de convocation envoie exclusivement un e-mail aux participants explicitement associés à la réunion. Au moment de la mise en file, chaque participant doit encore référencer un membre du conseil actif, non supprimé, ainsi qu’un compte utilisateur actif disposant d’une adresse e-mail.

Les participants inéligibles sont retournés sous forme d’identifiants internes et de codes de motif seulement. Leur nom et leur adresse ne sont ni audités ni ajoutés aux erreurs. Sans destinataire éligible, l’action répond `422` et laisse la réunion à l’état `draft`.

L’autorisation demeure canonique : le superadmin conserve sa portée globale et un administrateur local doit satisfaire les contrôles de TASK-032B pour la mosquée du conseil.

## États de livraison

- `notice_queue_claimed_at` protège la mise en file contre les doubles soumissions concurrentes ; une réclamation orpheline peut être reprise après cinq minutes.
- `notice_queued_at` signifie que le job chiffré de toutes les convocations éligibles a été accepté par la queue.
- `notice_sent_at` au niveau d’un participant signifie que le transport mail a accepté ce message.
- `notice_sent_at` au niveau de la réunion n’est renseigné que lorsque tous les participants mis en file ont réussi.
- `notice_failed_at` et `notice_attempts` suivent un échec final individuel et le nombre de tentatives.

Ces champs ne prouvent jamais la livraison dans la boîte du destinataire ni la lecture. La migration conserve une ancienne valeur historique de `notice_sent_at` dans `legacy_notice_recorded_at`, puis remet l’agrégat strict à zéro.

## Exécution et reprise

`SendCouncilMeetingNotices` est un job `ShouldBeEncrypted` et `ShouldQueueAfterCommit`. Il effectue au maximum trois essais, avec des délais de 60, 300 et 900 secondes. Son payload contient uniquement les identifiants et versions techniques de la réunion et des participants.

Une version de diffusion au niveau de la réunion et une version de livraison par participant rendent les anciens jobs inopérants. Un job ne transmet rien si la réunion n’est plus `convened`, si sa version est obsolète, si le participant a déjà réussi ou si le membre ou le compte est devenu inactif.

Après un échec final partiel, rappeler l’action de convocation remet en file uniquement les participants éligibles encore en échec. Les succès précédents ne sont pas renvoyés. Une nouvelle convocation d’annulation n’est pas implémentée dans cette tranche.

Le verrouillage empêche deux workers concurrents d’envoyer la même version. Comme tout transport mail sans clé d’idempotence fournie par le prestataire, une panne du processus après acceptation SMTP mais avant l’enregistrement de `notice_sent_at` laisse une fenêtre résiduelle de nouvel envoi lors du retry ; l’application ne peut donc pas promettre une sémantique exactement-une-fois au-delà de sa transaction locale.

## Exploitation

Utiliser la même connexion de queue et les mêmes procédures `queue:work`, `queue:restart`, `queue:failed` et `queue:retry` que celles décrites dans [queued-invitations.md](queued-invitations.md). Les alertes doivent couvrir les jobs échoués, une croissance anormale de la queue, les réunions durablement sans `notice_sent_at` et les réclamations de queue anciennes.

La recette SMTP doit employer une boîte contrôlée et la configuration fournie par l’opérateur. La CI et les tests utilisent des transports simulés ; ils ne valident pas un fournisseur SMTP réel, les rebonds, les plaintes ou la lecture.
