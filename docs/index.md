# Alfred — Documentation

Alfred est un plugin Jeedom qui intègre un assistant IA directement dans votre tableau de bord domotique. Il supporte plusieurs fournisseurs de LLM (Claude, GPT, Gemini), s'appuie sur le protocole MCP pour interagir avec vos équipements, et mémorise des informations entre les sessions.

## Sommaire

| Page | Contenu |
|---|---|
| [Installation](installation.md) | Prérequis, installation depuis la Market, première activation |
| [Configuration](configuration.md) | Fournisseur LLM, clé API, prompt système, options avancées |
| [Serveurs MCP](mcp.md) | Connecter Alfred à JeedomMCP et d'autres serveurs MCP |
| [Interface de chat](utilisation.md) | Sessions, envoi de messages, affichage Markdown, débogage |
| [Mémoire persistante](memoire.md) | Sauvegarder et gérer les informations entre sessions |
| [Planification](planification.md) | Demander à Alfred d'agir plus tard |
| [Saisie et synthèse vocale](voix.md) | Microphone, dictée, TTS, sélection de voix |
| [Architecture technique](architecture.md) | Pour les développeurs : classes PHP, API SSE, base de données |

## Fonctionnalités principales

- **Multi-fournisseur** : Anthropic Claude, OpenAI GPT, Google Gemini
- **Streaming temps réel** : les réponses s'affichent mot par mot
- **Outils MCP** : Alfred peut contrôler vos équipements Jeedom via JeedomMCP
- **Mémoire persistante** : Alfred se souvient des informations entre les conversations
- **Planification** : Alfred peut exécuter des actions différées ("éteins les lumières dans 30 minutes")
- **Multi-sessions** : historique de conversations accessible dans la barre latérale
- **PWA** : installable sur mobile comme une application native (Phase 6)
