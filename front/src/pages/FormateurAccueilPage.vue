<script setup>
// Accueil formateur : accès aux outils (fichiers, atelier SQL, journal). Planning, signatures et évaluations côté formateur : non construits.
import Icone from '@/components/Icone.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
</script>

<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-xl font-semibold text-alag-navy">Bonjour {{ auth.nomComplet }}</h1>
      <p class="text-sm text-slate-600">Connecté en tant que {{ auth.utilisateur?.role === 'admin' ? 'administrateur' : 'formateur' }}.</p>
    </div>
    <div class="grid gap-3 sm:grid-cols-2">
      <RouterLink :to="{ name: 'admin-fichiers' }" class="sc-tile">
        <span class="sc-tile-icone"><Icone nom="dossier" class="h-5 w-5" /></span>
        <div class="min-w-0">
          <div class="font-semibold text-slate-900">Gestion des fichiers</div>
          <div class="text-xs text-slate-600">Arborescence serveur, sélection multiple et ajout groupé par client/cours, aperçu stagiaire en direct.</div>
        </div>
      </RouterLink>
      <RouterLink :to="{ name: 'admin-atelier-entrainement' }" class="sc-tile">
        <span class="sc-tile-icone"><Icone nom="sql" class="h-5 w-5" /></span>
        <div class="min-w-0">
          <div class="font-semibold text-slate-900">Atelier SQL — éditeur</div>
          <div class="text-xs text-slate-600">Le même éditeur que les stagiaires, pour préparer ou faire une démonstration.</div>
        </div>
      </RouterLink>
      <RouterLink :to="{ name: 'admin-atelier-partiel' }" class="sc-tile">
        <span class="sc-tile-icone"><Icone nom="evaluation" class="h-5 w-5" /></span>
        <div class="min-w-0">
          <div class="font-semibold text-slate-900">Atelier SQL — créer un partiel</div>
          <div class="text-xs text-slate-600">Programmer une évaluation SQL sur une promo.</div>
        </div>
      </RouterLink>
      <RouterLink :to="{ name: 'admin-atelier-consignes' }" class="sc-tile">
        <span class="sc-tile-icone"><Icone nom="consignes" class="h-5 w-5" /></span>
        <div class="min-w-0">
          <div class="font-semibold text-slate-900">Atelier SQL — consignes</div>
          <div class="text-xs text-slate-600">Banque d'exercices : créer, modifier, masquer, supprimer, pointer les tentatives.</div>
        </div>
      </RouterLink>
      <RouterLink :to="{ name: 'admin-atelier-stats' }" class="sc-tile">
        <span class="sc-tile-icone"><Icone nom="quizz" class="h-5 w-5" /></span>
        <div class="min-w-0">
          <div class="font-semibold text-slate-900">Atelier SQL — correction & stats</div>
          <div class="text-xs text-slate-600">Requêtes des stagiaires, notes, bonus et statistiques par promo.</div>
        </div>
      </RouterLink>
      <RouterLink :to="{ name: 'admin-atelier-suivi' }" class="sc-tile">
        <span class="sc-tile-icone"><Icone nom="positionnement" class="h-5 w-5" /></span>
        <div class="min-w-0">
          <div class="font-semibold text-slate-900">Atelier SQL — suivi en direct</div>
          <div class="text-xs text-slate-600">Stagiaires actifs, messagerie, détection d'abus de lancement de requêtes.</div>
        </div>
      </RouterLink>
      <RouterLink :to="{ name: 'admin-journal' }" class="sc-tile">
        <span class="sc-tile-icone"><Icone nom="fichier" class="h-5 w-5" /></span>
        <div class="min-w-0">
          <div class="font-semibold text-slate-900">Journal des connexions</div>
          <div class="text-xs text-slate-600">Connexions et échecs, filtrable par identifiant.</div>
        </div>
      </RouterLink>
    </div>
  </div>
</template>
