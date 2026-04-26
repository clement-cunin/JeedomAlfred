# Mémoire persistante

Alfred dispose d'un système de mémoire qui lui permet de retenir des informations entre les sessions et de les réutiliser automatiquement dans chaque conversation.

## Portées

| Portée | Visibilité | Exemples d'usage |
|---|---|---|
| **Globale** | Tous les utilisateurs | Nom des pièces, équipements, préférences du foyer |
| **Personnelle** | Utilisateur concerné uniquement | Préférences individuelles, routines, rappels |

## Injection automatique

À chaque début de conversation, Alfred charge toutes les mémoires applicables (globales + personnelles de l'utilisateur) et les intègre dans son prompt système. Il n'est pas nécessaire de rappeler ces informations à chaque message.

## Gestion par Alfred

Alfred peut gérer sa propre mémoire directement dans la conversation, grâce à des outils internes :

### Sauvegarder une information

> "Alfred, souviens-toi que la salle de bain du bas est au rez-de-chaussée."

Alfred appellera `alfred_memory_save` avec un libellé et le contenu à retenir. Vous pouvez préciser la portée :
- **"souviens-toi pour tout le monde"** → mémoire globale
- **"souviens-toi pour moi"** → mémoire personnelle (par défaut)

### Mettre à jour une information

> "En fait, ma préférence pour la température du salon est 21°C, pas 20°C."

Alfred retrouve la mémoire par son libellé et met à jour son contenu (`alfred_memory_update`).

### Oublier une information

> "Oublie ce que tu sais sur mon heure de réveil."

Alfred supprime la mémoire correspondante (`alfred_memory_forget`).

## Gestion par l'administrateur

La page de configuration d'Alfred (section **Mémoire**) liste toutes les entrées. L'administrateur peut :
- Modifier le libellé, le contenu ou changer la portée d'une entrée
- Supprimer n'importe quelle entrée sans restriction de portée

## Onboarding

Deux mécanismes permettent à Alfred de construire sa mémoire dès la première utilisation :

- **Prompt de première installation** : injecté lors de la toute première conversation (aucune mémoire globale). Utilisez-le pour décrire votre maison, vos équipements, vos habitudes.
- **Prompt nouvel utilisateur** : injecté pour tout utilisateur n'ayant pas encore de mémoires personnelles. Alfred peut ainsi poser des questions d'onboarding au premier contact.

Ces prompts sont configurables dans la page de configuration (voir [Configuration](configuration.md)).

## Base de données

Les mémoires sont stockées dans la table `alfred_memory` :

| Colonne | Type | Description |
|---|---|---|
| `id` | INT | Identifiant unique |
| `scope` | VARCHAR | `global` ou `user:{login}` |
| `label` | VARCHAR(100) | Libellé court (clé de mise à jour/suppression) |
| `content` | TEXT | Contenu de la mémoire |
| `created_at` | DATETIME | Date de création |
| `updated_at` | DATETIME | Date de dernière modification |
