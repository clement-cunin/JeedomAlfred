# Configuration

La page de configuration est accessible via **Plugins → Alfred → Configuration** (icône engrenage).

## Fournisseur LLM

### Choix du fournisseur

Alfred supporte trois fournisseurs :

| Fournisseur | Modèles typiques | Notes |
|---|---|---|
| **Anthropic** | claude-sonnet-4-6, claude-opus-4-7 | Streaming SSE natif |
| **OpenAI** | gpt-4o, gpt-4o-mini, o1, o3 | Streaming SSE natif |
| **Google Gemini** | gemini-1.5-pro, gemini-2.0-flash | Streaming simulé |

### Clé API

Saisissez votre clé API dans le champ correspondant. Cliquez sur **Tester la connexion** pour vérifier que la clé est valide et que le fournisseur est joignable.

### Choix du modèle

Cliquez sur **Charger les modèles** pour récupérer la liste des modèles disponibles depuis l'API du fournisseur, puis sélectionnez le modèle souhaité dans la liste déroulante.

> **Conseil** : Pour un usage quotidien, préférez les modèles "sonnet" ou "flash" (plus rapides et moins coûteux). Réservez "opus" ou "pro" aux tâches complexes.

## Prompt système

Le prompt système définit la personnalité et le comportement d'Alfred. Il est injecté au début de chaque conversation.

Alfred complète automatiquement le prompt avec :
- La date et l'heure actuelles
- L'identifiant et le rôle Jeedom de l'utilisateur
- Les mémoires persistantes (globales et personnelles)
- Les prompts d'onboarding si nécessaire (voir ci-dessous)

### Prompt de première installation

Ce prompt est injecté une seule fois, lors de la toute première conversation après l'installation (aucune mémoire globale n'existe encore). Utilisez-le pour présenter le contexte de votre maison à Alfred.

### Prompt nouvel utilisateur

Ce prompt est injecté lorsqu'un utilisateur n'a encore aucune mémoire personnelle. Il permet à Alfred de recueillir ses préférences dès le premier échange.

## Nombre maximum d'itérations

Limite le nombre de cycles de raisonnement de l'agent (appels LLM + exécutions d'outils) par message. Valeur par défaut : **10**.

Si la limite est atteinte, l'interface propose des boutons **+5**, **+10**, **+20** pour continuer sans ressaisir le message.

## Serveurs MCP

Voir la documentation dédiée : [Serveurs MCP](mcp.md).

## Base de données

La section **Base de données** affiche la version du schéma actuel. Le bouton **Réparer** exécute manuellement les migrations en attente si une mise à jour a échoué.

## Mémoire

La section **Mémoire** liste toutes les entrées sauvegardées (mémoires globales et par utilisateur). Depuis cette interface, les administrateurs peuvent :
- Modifier le libellé, le contenu ou la portée d'une entrée
- Supprimer une entrée

Voir la documentation complète : [Mémoire persistante](memoire.md).
