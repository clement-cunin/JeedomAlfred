# Interface de chat

## Vue d'ensemble

L'interface Alfred se compose de deux zones :
- **Barre latérale** (gauche) : liste des conversations et bouton Nouvelle conversation
- **Zone de chat** (droite) : historique des messages et barre de saisie

Sur mobile, la barre latérale se replie et s'ouvre via le bouton hamburger.

## Conversations (sessions)

### Créer une nouvelle conversation

Cliquez sur **Nouvelle conversation** en haut de la barre latérale. Une nouvelle session est créée dès l'envoi du premier message.

### Changer de conversation

Cliquez sur n'importe quelle conversation dans la barre latérale pour la charger. L'historique complet s'affiche dans la zone de chat.

### Renommer une conversation

Double-cliquez sur le titre d'une conversation dans la barre latérale pour le modifier. Par défaut, le titre est extrait du premier message de l'utilisateur (60 caractères max).

### Supprimer une conversation

Passez la souris sur une conversation dans la barre latérale et cliquez sur l'icône de suppression. Cette action supprime également tous les messages associés.

## Envoyer un message

1. Tapez votre message dans la zone de texte
2. Appuyez sur **Entrée** ou cliquez sur le bouton **Envoyer**

> **Raccourci** : `Shift + Entrée` pour insérer un saut de ligne sans envoyer.

## Streaming des réponses

Les réponses d'Alfred s'affichent en temps réel, mot par mot. Pendant le traitement :
- Un indicateur "en train d'écrire" (trois points animés) s'affiche
- Les appels d'outils MCP apparaissent sous forme de badges avec une icône de chargement, puis une coche à la fin

## Affichage Markdown

Alfred supporte un sous-ensemble de Markdown dans ses réponses :

| Syntaxe | Rendu |
|---|---|
| `**texte**` | **texte** |
| `*texte*` | *texte* |
| `` `code` `` | `code` |
| ` ```bloc``` ` | bloc de code |
| `### Titre` | Titre de niveau 3–5 |
| `- item` | Liste à puces |

## Limite d'itérations

Quand Alfred atteint la limite d'itérations configurée (défaut : 10), un message d'information s'affiche avec trois boutons : **+5**, **+10**, **+20**. Cliquez sur l'un d'eux pour autoriser Alfred à continuer sans ressaisir votre message.

## Mode débogage (administrateurs)

Si vous êtes connecté en tant qu'administrateur Jeedom, un bloc **Prompt système** apparaît en bas de la zone de chat (section rétractable). Il affiche le prompt complet envoyé au LLM, incluant les mémoires injectées et les informations utilisateur — utile pour diagnostiquer un comportement inattendu.

## Écran d'accueil

Lors de la première utilisation (aucune conversation existante), un écran de bienvenue s'affiche avec quelques exemples de questions pour démarrer.
