# Planification différée

Alfred peut exécuter des actions à une heure ultérieure, sans que vous ayez besoin de rester connecté ou de répéter votre demande.

## Utilisation

Demandez simplement à Alfred en langage naturel :

> "Éteins les lumières du salon dans 30 minutes."

> "Rappelle-moi de sortir les poubelles demain matin à 8h."

> "Lance le scénario 'Nuit' dans 2 heures."

Alfred utilisera automatiquement l'outil `alfred_schedule` pour planifier l'action.

## Stratégies de planification

Alfred choisit la stratégie selon le délai demandé :

| Délai | Stratégie | Mécanisme |
|---|---|---|
| < 15 minutes | **Background** | Processus PHP en arrière-plan (`nohup`) lancé immédiatement avec le délai en argument |
| ≥ 15 minutes | **Cron** | Enregistré en base de données, exécuté par le cron Jeedom (toutes les minutes) |

## Comportement lors de l'exécution

Quand l'heure arrive, Alfred reprend la conversation dans laquelle la planification a été créée et exécute l'instruction. Cela lui donne accès à tout le contexte de la session d'origine (équipements mentionnés, préférences, etc.).

## Limitations

- Les tâches de stratégie **background** dépendent du processus PHP lancé : un redémarrage du serveur avant l'échéance annule la tâche.
- Les tâches de stratégie **cron** sont persistées en base de données et survivent aux redémarrages.
- La précision est d'environ **1 minute** (granularité du cron Jeedom).

## Base de données

Les tâches planifiées sont stockées dans la table `alfred_schedule` :

| Colonne | Type | Description |
|---|---|---|
| `id` | INT | Identifiant unique |
| `session_id` | VARCHAR | Session de conversation d'origine |
| `instruction` | TEXT | Instruction à exécuter |
| `run_at` | DATETIME | Heure d'exécution prévue |
| `strategy` | ENUM | `background` ou `cron` |
| `status` | ENUM | `pending`, `running`, `done`, `error` |
| `error_msg` | TEXT | Message d'erreur si échec |
