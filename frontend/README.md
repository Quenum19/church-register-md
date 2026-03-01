# Church Register — Système d'enregistrement membres

## Stack
- **Frontend** : React + Tailwind CSS
- **Backend** : Node.js / Express
- **Base de données** : MongoDB Atlas
- **Auth** : JWT + bcrypt
- **Déploiement** : PlanetHoster (Passenger)

## Lancement local
```bash
# Backend
cd backend && npm run dev

# Frontend (autre terminal)
cd frontend && npm start
```

## Rôles
| Rôle        | Voir | Éditer | Supprimer | Gérer admins |
|-------------|------|--------|-----------|--------------|
| super_admin | ✅   | ✅     | ✅        | ✅           |
| moderateur  | ✅   | ✅     | ❌        | ❌           |
| lecteur     | ✅   | ❌     | ❌        | ❌           |