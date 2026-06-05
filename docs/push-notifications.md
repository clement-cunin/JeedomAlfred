# Notifications push

Alfred peut envoyer des notifications push sur vos appareils mobiles directement depuis vos scénarios Jeedom. Lorsque vous tapez la notification, la conversation Alfred correspondante s'ouvre automatiquement.

---

## Prérequis

- La PWA Alfred doit être accessible en **HTTPS** (nécessaire pour l'API Web Push).
- L'appareil doit utiliser un navigateur supportant `PushManager` : Chrome, Edge, Firefox, Safari 16.4+.
- Sur iOS, la PWA doit être installée via « Ajouter à l'écran d'accueil » — les onglets Safari ne supportent pas les push.

---

## Activation sur l'appareil

1. Ouvrez la PWA Alfred (`https://…/plugins/alfred/chat/index.php`).
2. Connectez-vous avec votre compte Jeedom si ce n'est pas déjà le cas.
3. En bas de la barre latérale, cliquez sur **🔔 Activer les notifications**.
4. Acceptez la permission dans la boîte de dialogue du navigateur.

Un équipement **Téléphone** est alors créé automatiquement dans Alfred (ex. : « iPhone Clément »), et deux commandes action y sont attachées.

Pour désactiver, cliquez à nouveau sur le bouton (il indique « Notifications activées »).

---

## Commandes scénario

Chaque équipement Téléphone expose deux commandes de type `action / message` :

| Commande | logicalId | Comportement |
|---|---|---|
| **Envoyer message** | `alfred_push_simple` | Envoie le message en push directement, sans passer par le LLM |
| **Démarrer une réflexion** | `alfred_push_reflect` | Soumet le message à Alfred, récupère sa réponse, puis l'envoie en push |

Le paramètre **Message** supporte les tags Jeedom : `#[Salon][Aspirateur][Etat]#`, `#time#`, etc.

### Exemple de scénario

```
Si aspirateur.etat NOT IN (en_action, en_charge) ET heure > 20h
→ iPhone Clément > Démarrer une réflexion
    Message : "L'aspirateur est en état #[Salon][Aspirateur][Etat]# depuis 30 min, que fais-je ?"
```

Alfred réfléchit, produit une réponse (ex. : « L'aspirateur semble bloqué, vérifie le bac à poussière. »), puis vous l'envoie en notification.

---

## Mode « Réflexion » — fonctionnement

Le mode Réflexion est **asynchrone** : la commande scénario rend la main immédiatement, sans attendre la réponse du LLM.

```
Scénario déclenche la commande
  → Jeedom crée une tâche alfred_async_task (type push_reflect)
  → Un processus PHP est lancé en arrière-plan (push_wakeup.php)
      → Alfred reçoit le message, raisonne, produit une réponse
      → La notification est sauvegardée en base
      → Un push vide est envoyé à l'appareil
          → Le service worker récupère la notification via /api/push.php
          → La notification s'affiche sur l'écran
  → Tap → chat/index.php?session=<id> → conversation Alfred ouverte
```

Le délai typique est de **5 à 15 secondes** selon le fournisseur LLM.

---

## Gestion des appareils

Dans la **page de configuration du plugin** (Alfred → Configuration), la section **Téléphones** liste tous les appareils enregistrés avec :

- Nom de l'appareil (dérivé du User-Agent au moment de l'activation)
- Statut (activé / désactivé)
- Nombre de subscriptions actives
- Bouton **Supprimer** — supprime l'équipement, ses commandes et ses subscriptions

### Régénération des clés VAPID

Les clés VAPID sont générées automatiquement à la première activation. En cas de besoin (rotation de sécurité, clé compromise), cliquez sur **Régénérer les clés VAPID** dans la section Téléphones.

> ⚠️ Après une régénération, **tous les appareils enregistrés doivent réactiver les notifications** depuis la PWA. Les anciennes subscriptions ne reçoivent plus rien.

---

## Architecture technique

### Protocole : push vide + piggyback

Alfred utilise un push **sans payload chiffré** (conforme RFC 8030). Le service worker reçoit un signal vide, puis récupère le contenu via une requête fetch authentifiée par un `fetch_token`.

Avantages :
- Pas d'implémentation de RFC 8291 (chiffrement AES-128-GCM) — compatible PHP 7.4+.
- Le contenu voyage sur HTTPS, déjà chiffré end-to-end par TLS.

### Tables MySQL ajoutées (migration 3)

```sql
alfred_push_subscription
  id, eqLogic_id, endpoint (VARCHAR 500), p256dh_key, auth_key,
  fetch_token (VARCHAR 64), user_agent, created_at

alfred_push_notification
  id, eqLogic_id, session_id, title, body, read_at, created_at
```

### Authentification

| Endpoint | Méthode | Auth |
|---|---|---|
| `push.php?action=vapid_public` | GET | Aucune |
| `push.php?action=subscribe` | POST | Session Jeedom ou `user_hash` |
| `push.php?action=pending` | POST | `fetch_token` (service worker) |
| `push.php?action=read` | POST | `fetch_token` (service worker) |

### Fichiers concernés

| Fichier | Rôle |
|---|---|
| `core/class/alfredPush.class.php` | Logique VAPID, push, subscriptions, notifications |
| `core/class/alfred.class.php` | `isPhone()`, `getPhones()`, `postSave()` |
| `core/class/alfredCmd.class.php` | `execute()` : dispatch simple / async reflect |
| `api/push.php` | API HTTP pour la PWA et le service worker |
| `api/push_wakeup.php` | Script CLI pour le mode Réflexion (background) |
| `chat/sw.js` | Events `push` et `notificationclick` |
| `chat/index.php` | Bouton d'activation + JS subscription |
| `plugin_info/configuration.php` | Section Téléphones dans la config admin |
