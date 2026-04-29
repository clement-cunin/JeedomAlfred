# Installation

## Prérequis

- **Jeedom 4.4 ou supérieur**
- Un compte chez au moins un fournisseur LLM :
  - [Anthropic](https://console.anthropic.com/) (Claude)
  - [OpenAI](https://platform.openai.com/) (GPT)
  - [Google AI Studio](https://aistudio.google.com/) (Gemini)
- Le plugin **JeedomMCP** installé et configuré (recommandé, pour que Alfred contrôle vos équipements)

## Installation depuis la Market

1. Ouvrez **Jeedom → Plugins → Gestion des plugins**
2. Cliquez sur **Market** et recherchez "Alfred"
3. Installez le plugin (version bêta)
4. Activez-le depuis la page de gestion des plugins

## Première configuration

Après installation, Jeedom exécute automatiquement les migrations de base de données. Quatre tables sont créées :

| Table | Rôle |
|---|---|
| `alfred_conversation` | Sessions de chat |
| `alfred_message` | Historique des messages |
| `alfred_memory` | Mémoire persistante |
| `alfred_schedule` | Tâches planifiées |

## Accéder à Alfred

Alfred apparaît dans le menu Plugins de Jeedom. Cliquez sur **Alfred** pour ouvrir l'interface de chat.

> **Note** : Si aucune clé API n'est configurée, tous les contrôles de saisie sont désactivés. Vous devez d'abord compléter la [configuration](configuration.md).

## Mise à jour

Les mises à jour appliquent automatiquement les nouvelles migrations de base de données. Aucune action manuelle n'est requise.

## Désinstallation

La désinstallation supprime toutes les tables de la base de données (conversations, messages, mémoires, tâches planifiées). **Cette opération est irréversible.**
