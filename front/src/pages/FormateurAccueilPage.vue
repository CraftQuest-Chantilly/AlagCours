<script setup>
// Accueil formateur : écran minimal. Étape 8 (25/09) — première brique construite : gestion des
// fichiers/droits par formation. Planning, signatures et évaluations côté formateur restent à
// construire (pas encore spécifiés/validés). Le bouton « Back-office » évoqué en étape 1 (accès
// SuiviCours_Front pour formateur/admin) n'est pas non plus branché : URL/mécanisme à définir.
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
</script>

<template>
  <div class="space-y-6">
    <h1 class="text-xl font-semibold text-alag-navy">Bonjour {{ auth.nomComplet }}</h1>

    <section class="sc-card p-4 text-sm text-slate-700">
      <p class="mb-2">
        Vous êtes connecté en tant que <strong>{{ auth.utilisateur?.role === 'admin' ? 'administrateur' : 'formateur' }}</strong>.
      </p>
      <p>
        L'espace formateur est en cours de construction. Planning, signatures et évaluations
        côté formateur ne sont pas encore là — dites-moi ce que vous voulez voir et on l'ajoute
        à l'étape suivante.
      </p>
    </section>

    <RouterLink :to="{ name: 'admin-fichiers' }"
                class="flex items-center justify-between gap-3 rounded-xl bg-[#0f5a7d] px-4 py-4 text-white shadow-sm hover:bg-[#0c4a68]">
      <div>
        <div class="font-semibold">Gestion des fichiers</div>
        <div class="text-xs text-white/80">Arborescence serveur, sélection multiple et ajout groupé par client/cours (regroupement TD), aperçu stagiaire en direct.</div>
      </div>
      <span class="text-2xl" aria-hidden="true">📁</span>
    </RouterLink>

    <RouterLink :to="{ name: 'admin-atelier-entrainement' }"
                class="flex items-center justify-between gap-3 rounded-xl bg-[#0f5a7d] px-4 py-4 text-white shadow-sm hover:bg-[#0c4a68]">
      <div>
        <div class="font-semibold">Atelier SQL — tester l'éditeur</div>
        <div class="text-xs text-white/80">Le même éditeur que les stagiaires (bases, schéma, requêtes SELECT), pour préparer ou faire une démonstration.</div>
      </div>
      <span class="text-2xl" aria-hidden="true">🧪</span>
    </RouterLink>

    <RouterLink :to="{ name: 'admin-atelier-partiel' }"
                class="flex items-center justify-between gap-3 rounded-xl bg-[#0f5a7d] px-4 py-4 text-white shadow-sm hover:bg-[#0c4a68]">
      <div>
        <div class="font-semibold">Atelier SQL — créer un partiel</div>
        <div class="text-xs text-white/80">Mini-écran provisoire pour programmer une évaluation SQL sur une promo.</div>
      </div>
      <span class="text-2xl" aria-hidden="true">🗄</span>
    </RouterLink>

    <RouterLink :to="{ name: 'admin-atelier-consignes' }"
                class="flex items-center justify-between gap-3 rounded-xl bg-[#0f5a7d] px-4 py-4 text-white shadow-sm hover:bg-[#0c4a68]">
      <div>
        <div class="font-semibold">Atelier SQL — BackOffice consignes</div>
        <div class="text-xs text-white/80">Gérer la banque d'exercices en pratique libre (créer, modifier, masquer, supprimer, pointer les tentatives).</div>
      </div>
      <span class="text-2xl" aria-hidden="true">📋</span>
    </RouterLink>

    <RouterLink :to="{ name: 'admin-atelier-suivi' }"
                class="flex items-center justify-between gap-3 rounded-xl bg-[#0f5a7d] px-4 py-4 text-white shadow-sm hover:bg-[#0c4a68]">
      <div>
        <div class="font-semibold">Atelier SQL — suivi en direct</div>
        <div class="text-xs text-white/80">Stagiaires actifs, messagerie, et détection d'abus de lancement de requêtes.</div>
      </div>
      <span class="text-2xl" aria-hidden="true">📡</span>
    </RouterLink>

    <RouterLink :to="{ name: 'admin-journal' }"
                class="flex items-center justify-between gap-3 rounded-xl bg-[#0f5a7d] px-4 py-4 text-white shadow-sm hover:bg-[#0c4a68]">
      <div>
        <div class="font-semibold">Journal des connexions</div>
        <div class="text-xs text-white/80">Historique des connexions et échecs de connexion, filtrable par identifiant.</div>
      </div>
      <span class="text-2xl" aria-hidden="true">📜</span>
    </RouterLink>
  </div>
</template>
