# Serveurs MCP

Alfred utilise le protocole MCP (Model Context Protocol) pour accéder à des outils externes — notamment les équipements et scénarios Jeedom via **JeedomMCP**.

## Ajouter un serveur MCP

Dans la section **Serveurs MCP** de la configuration :

1. Cliquez sur **Ajouter un serveur**
2. Renseignez les champs :
   - **Nom** : identifiant lisible (ex. `JeedomMCP`)
   - **URL** : endpoint MCP (ex. `https://votre-jeedom.duckdns.org/plugins/jeedomMCP/api/mcp.php`)
   - **Clé API** : si le serveur l'exige (champ `X-API-Key`)
   - **Slug** : préfixe court pour les outils (ex. `jeedom`) — utilisé en cas de conflit
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

## Fonctionnement technique

Lors de chaque conversation, Alfred :

1. Interroge tous les serveurs MCP actifs pour récupérer la liste des outils disponibles
2. Transmet cette liste au LLM dans le prompt système
3. Quand le LLM décide d'appeler un outil, Alfred route l'appel vers le bon serveur
4. Le résultat est renvoyé au LLM qui continue son raisonnement

Les outils sont mis en cache par serveur pour la durée de la session.

## Exemple : JeedomMCP

JeedomMCP expose typiquement des outils comme :
- `devices_list` — liste les équipements
- `command_execute` — exécute une commande
- `scenario_run` — lance un scénario
- `devices_states` — rafraîchit les états

Alfred peut ainsi répondre à "Éteins le salon" en appelant `command_execute` avec les bons paramètres, sans aucune configuration supplémentaire.
