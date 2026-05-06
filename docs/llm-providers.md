# Fournisseurs LLM

Alfred supporte plusieurs fournisseurs de modèles de langage. Le fournisseur actif se choisit dans **Plugins → Alfred → Configuration → Fournisseur IA**.

---

## Mistral AI

Fournisseur cloud de modèles open-weight (Mistral 7B, Mistral Large, etc.).

**Prérequis**
- Compte sur [console.mistral.ai](https://console.mistral.ai)
- Clé API générée depuis l'espace *API keys*

**Configuration**
| Champ | Valeur |
|---|---|
| Fournisseur | `Mistral AI` |
| Clé API | Clé `sk-...` copiée depuis la console |
| Modèle | Sélectionner dans la liste (chargée automatiquement au clic) |

**Modèles recommandés**
- `mistral-large-latest` — meilleur niveau de raisonnement, recommandé pour l'agentique
- `mistral-small-latest` — plus rapide et moins coûteux pour les usages simples

**Tool calling** : supporté sur tous les modèles `mistral-large` et `mistral-small`.

---

## Google Gemini

Fournisseur cloud Google (famille Gemini 1.5 / 2.0).

**Prérequis**
- Projet Google Cloud avec l'API *Generative Language* activée
- Clé API depuis [aistudio.google.com](https://aistudio.google.com) ou Google Cloud Console

**Configuration**
| Champ | Valeur |
|---|---|
| Fournisseur | `Google (Gemini)` |
| Clé API | Clé `AIza...` |
| Modèle | Sélectionner dans la liste |

**Modèles recommandés**
- `gemini-1.5-pro` — contexte long (1 M tokens), bon pour les conversations complexes
- `gemini-2.0-flash` — rapide et économique

**Tool calling** : supporté. Note : Gemini ne supporte pas nativement le streaming avec tool calling — Alfred simule le streaming en découpant la réponse.

---

## Ollama (local)

Exécution de modèles open-source **en local** sur votre propre machine ou réseau. Aucune donnée n'est envoyée à un service externe.

**Prérequis**
- [Ollama](https://ollama.com) installé et en cours d'exécution
- Au moins un modèle téléchargé (`ollama pull mistral`)
- Ollama accessible depuis le serveur Jeedom (même réseau local)

**Installation rapide (Windows)**
```powershell
# Installer depuis ollama.com, puis :
ollama pull mistral       # télécharge Mistral 7B (~4 GB)
ollama pull llama3.1      # alternative : Llama 3.1 8B
```

**Exposer Ollama sur le réseau local**

Par défaut, Ollama écoute uniquement sur `localhost`. Pour le rendre accessible depuis Jeedom (sur un autre appareil) :

```powershell
# Variable système (persistante après reboot)
[System.Environment]::SetEnvironmentVariable("OLLAMA_HOST", "0.0.0.0:11434", "Machine")
# Puis redémarrer Ollama
```

**Configuration dans Alfred**
| Champ | Valeur |
|---|---|
| Fournisseur | `Ollama (local)` |
| URL Ollama | `http://<IP_DE_LA_MACHINE>:11434` (ex: `http://192.168.1.50:11434`) |
| Modèle | Cliquer sur la liste pour charger les modèles installés |

**Modèles recommandés**

| Modèle | VRAM nécessaire | Tool calling | Notes |
|---|---|---|---|
| `mistral` | ~5 GB (Q4) | Oui | Bon équilibre qualité/vitesse |
| `llama3.1` | ~5 GB (Q4) | Oui | Très bon pour l'agentique |
| `qwen2.5` | ~5 GB (Q4) | Oui | Multilingue, bon en français |
| `phi3` | ~2 GB | Non | Ultra-léger, pas de tool calling |

> Le tool calling (indispensable pour l'agentique Alfred/MCP) n'est supporté que par certains modèles. Préférer `mistral`, `llama3.1` ou `qwen2.5`.

**Vérifier que Ollama tourne**
```
curl http://localhost:11434
# Réponse attendue : Ollama is running
```

**Limitations**
- Vitesse d'inférence dépendante du matériel local (GPU recommandé)
- Pas d'authentification native — ne pas exposer publiquement sans reverse proxy sécurisé
