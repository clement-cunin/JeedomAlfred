# Serveurs MCP

Alfred utilise le protocole MCP (Model Context Protocol) pour accéder à des outils externes — notamment les équipements et scénarios Jeedom via **JeedomMCP**.

## Ajouter un serveur MCP

Dans la section **Serveurs MCP** de la configuration :

1. Cliquez sur **Ajouter un serveur**
2. Renseignez les champs :
   - **Nom** : identifiant lisible (ex. `JeedomMCP`)
   - **Description** : phrase courte décrivant à quoi sert ce serveur — c'est le seul élément
     visible par le LLM tant que le serveur n'est pas activé, elle doit donc l'aider à décider
     quand l'activer (ex. "Équipements, scénarios et commandes domotiques Jeedom")
   - **URL** : endpoint MCP (ex. `https://votre-jeedom.duckdns.org/plugins/jeedomMCP/api/mcp.php`)
   - **Clé API** : si le serveur l'exige (champ `X-API-Key`)
   - **Slug** : préfixe court pour les outils (ex. `jeedom`) — utilisé en cas de conflit, et sert
     aussi de clé d'activation (voir ci-dessous) si renseigné
   - **Préfixer les outils** : si activé, les noms d'outils deviennent `slug__nom_outil`
3. Cliquez sur **Tester** pour vérifier la connexion et lister les outils disponibles

## Détection automatique de JeedomMCP

Si JeedomMCP est installé sur la même instance Jeedom, le bouton **Détecter JeedomMCP** remplit automatiquement l'URL et la clé API.

## Gestion des conflits

Quand deux serveurs exposent un outil de même nom, Alfred affiche un avertissement. Activez le préfixage sur l'un des serveurs pour lever le conflit. Le premier serveur déclaré a priorité sur les noms non préfixés.

## Activer / désactiver un serveur

Chaque serveur peut être activé ou désactivé individuellement sans le supprimer. Un serveur désactivé n'est pas interrogé lors des conversations.

## Ordre des serveurs

L'ordre d'affichage détermine la priorité en cas de conflit de noms d'outils. Réorganisez les serveurs par glisser-déposer.

## Fonctionnement technique — découverte en 2 temps

Pour limiter le nombre de tokens envoyés au LLM à chaque tour, les schémas d'outils des
serveurs MCP ne sont **pas** chargés par défaut :

1. Le prompt système ne contient qu'une liste courte des serveurs `enabled` configurés (nom +
   description), sous la section `## MCP servers`
2. Un outil générique, toujours disponible, `activate_mcp_server(server)`, permet au LLM de
   charger les outils d'un serveur donné quand il juge que c'est pertinent pour la requête en
   cours. Les nouveaux outils deviennent utilisables immédiatement, dans le même tour
3. Une fois activé, l'ensemble des outils du serveur est transmis au LLM à chaque tour suivant,
   comme précédemment — Alfred route les appels vers le bon serveur et renvoie le résultat au LLM

L'activation est **persistée en base** (colonne `active_mcp_servers` sur `alfred_conversation`,
une liste de clés de serveurs) et non déduite d'un ré-examen de l'historique des messages :
Alfred est sans état entre deux requêtes HTTP, donc c'est la seule source de vérité fiable pour
savoir quels serveurs recharger au tour suivant. Un serveur désactivé (`enabled=false`) ou
supprimé de la configuration ne peut pas être activé, même s'il apparaît encore dans l'état
persisté d'une conversation existante.

Les outils sont mis en cache par serveur pour la durée de la requête HTTP en cours.

## Exemple : JeedomMCP

JeedomMCP expose typiquement des outils comme :
- `devices_list` — liste les équipements
- `command_execute` — exécute une commande
- `scenario_run` — lance un scénario
- `devices_states` — rafraîchit les états

Alfred peut ainsi répondre à "Éteins le salon" en appelant `command_execute` avec les bons paramètres, sans aucune configuration supplémentaire.
