# Saisie vocale et synthèse vocale

Alfred intègre deux fonctionnalités vocales directement dans le navigateur, sans serveur tiers ni clé API supplémentaire. Elles s'appuient sur la Web Speech API, disponible nativement dans les navigateurs modernes (Chrome, Edge, Safari).

> **Compatibilité** : Firefox ne supporte pas la Web Speech API. Si votre navigateur n'est pas compatible, les boutons vocaux ne s'affichent pas.

---

## Saisie vocale (microphone → texte)

Le bouton microphone (icône 🎤) dans la barre de saisie permet de dicter un message à la voix.

### Modes de fonctionnement

Deux modes sont disponibles, contrôlés par le bouton **Auto-envoi** (à gauche du bouton micro) :

| Mode | Comportement |
|---|---|
| **Remplissage** (défaut) | Le texte transcrit s'accumule dans la zone de saisie. Vous envoyez manuellement quand vous avez terminé. |
| **Auto-envoi** | Dès que vous terminez de parler, le message est envoyé automatiquement. |

### Utilisation

1. Cliquez sur le bouton 🎤 pour démarrer l'écoute (le bouton devient rouge et pulse)
2. Parlez — la transcription s'affiche en temps réel dans la zone de saisie
3. En mode **Remplissage** : cliquez à nouveau sur 🎤 pour arrêter, puis sur **Envoyer**
4. En mode **Auto-envoi** : arrêtez de parler — Alfred envoie dès la fin de la phrase détectée

### Langue

La langue de reconnaissance est celle du navigateur (`navigator.language`). Pour le français, assurez-vous que votre navigateur est configuré en `fr-FR` ou `fr`.

### Dictées successives

En mode **Remplissage**, plusieurs dictées peuvent se succéder sans vider la zone de saisie — le texte s'accumule. Cela permet de dicter un message en plusieurs fois.

---

## Synthèse vocale (texte → voix)

Alfred peut lire ses réponses à voix haute à l'aide du synthétiseur vocal du navigateur.

### Activer / désactiver

Cliquez sur le bouton **TTS** (icône haut-parleur) dans la barre de saisie. L'état est mémorisé dans le navigateur (`localStorage`) entre les sessions.

Quand le TTS est activé, chaque réponse complète d'Alfred est automatiquement lue à voix haute dès réception.

### Paramètres vocaux

Cliquez sur l'icône d'engrenage à côté du bouton TTS pour ouvrir le panneau de réglages :

| Réglage | Description |
|---|---|
| **Voix** | Sélecteur parmi les voix installées sur l'appareil. La voix correspondant à la langue du navigateur est présélectionnée. |
| **Vitesse** | Curseur de 0.5× à 2.0× (pas de 0.1). La valeur par défaut est 1.0×. |

Les réglages sont persistés dans le navigateur (`localStorage`).

### Comportement pendant la lecture

- Envoyer un nouveau message interrompt la lecture en cours
- Désactiver le TTS interrompt également la lecture immédiatement
- Le Markdown est automatiquement retiré avant la lecture (balises code, gras, italique, titres, liens) pour un rendu oral naturel

---

## Résumé des contrôles

| Bouton | Fonction |
|---|---|
| 🎤 (micro) | Démarrer / arrêter la saisie vocale |
| ⟳ (auto-envoi) | Activer / désactiver l'envoi automatique après dictée |
| 🔊 (TTS) | Activer / désactiver la synthèse vocale automatique |
| ⚙️ (réglages TTS) | Ouvrir le panneau voix + vitesse |
