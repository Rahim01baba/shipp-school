import { api } from '../api/client.js'

// Suivi GPS du telephone du chauffeur pendant une course (migration 006).
//
// - Le suivi n'existe que pendant une course : demarre avec « Demarrer la course »,
//   arrete avec « Terminer la course ». Jamais 24 h / 24.
// - Positions echantillonnees (10 s en mouvement, 20 s a l'arret, allonge si batterie
//   faible), mises en file d'attente locale puis envoyees par lots : une coupure reseau
//   ne perd rien, le renvoi ne cree pas de doublon (cle trajet + heure de mesure).
// - Le suivi vit hors des ecrans : il continue si le chauffeur ouvre le scanner.
//
// LIMITE (honnete) : dans un navigateur, le systeme peut suspendre la page quand
// l'ecran est verrouille ou l'application en arriere-plan. On garde l'ecran allume
// (Wake Lock) pendant la course et on signale au Back Office les passages en
// arriere-plan ; la carte affiche alors « position ancienne » / « GPS perdu ».
// Un suivi garanti ecran verrouille demande l'application mobile (voir docs/GPS_ARRIERE_PLAN.md).

const CLE_FILE = 'shipp_gps_file_'
const CLE_COURSE = 'shipp_gps_course'
const LOT_MAX = 200
const FILE_MAX = 5000

function lire(cle, defaut) {
  try {
    const v = localStorage.getItem(cle)
    return v ? JSON.parse(v) : defaut
  } catch {
    return defaut
  }
}
function ecrire(cle, v) {
  try {
    if (v === null) localStorage.removeItem(cle)
    else localStorage.setItem(cle, JSON.stringify(v))
  } catch {
    // stockage plein ou interdit : la file reste en memoire
  }
}

function distance(a, b) {
  const r = 6371000
  const dLat = ((b.lat - a.lat) * Math.PI) / 180
  const dLng = ((b.lng - a.lng) * Math.PI) / 180
  const x = Math.sin(dLat / 2) ** 2 + Math.cos((a.lat * Math.PI) / 180) * Math.cos((b.lat * Math.PI) / 180) * Math.sin(dLng / 2) ** 2
  return 2 * r * Math.asin(Math.min(1, Math.sqrt(x)))
}

const ETAT_INITIAL = {
  actif: false, trajetId: null, gps: 'arrete', message: null, derniereMesure: null, dernierEnvoi: null,
  enAttente: 0, enLigne: typeof navigator === 'undefined' ? true : navigator.onLine !== false,
  ecranMaintenu: false, arrierePlan: false, batterie: null, aide: null, erreur: null, statutTrajet: null,
}

class SuiviGps {
  constructor() {
    this.etat = { ...ETAT_INITIAL }
    this.abonnes = new Set()
    this.watchId = null
    this.minuterie = null
    this.envoiEnCours = false
    this.prochainEssai = 0
    this.attente = 5000
    this.intervalle = 10
    this.precisionFaible = 100
    this.dernierMis = null
    this.signalEnvoye = null
    this.verrou = null
    this.batterieObj = null
    this.file = []
    this._surVisibilite = this._surVisibilite.bind(this)
    this._surReseau = this._surReseau.bind(this)
  }

  abonner(fn) {
    this.abonnes.add(fn)
    fn(this.etat)
    return () => this.abonnes.delete(fn)
  }

  _maj(p) {
    this.etat = { ...this.etat, ...p }
    this.abonnes.forEach((fn) => fn(this.etat))
  }

  /** Course memorisee (reprise apres rechargement de la page). */
  courseMemorisee() {
    return lire(CLE_COURSE, null)
  }

  async demarrer({ trajetId, vehicleId = null, intervalle = 10, precisionFaible = 100 }) {
    if (this.etat.actif && this.etat.trajetId === trajetId) return
    if (this.etat.actif) await this.arreter({ vider: true })
    this.trajetId = trajetId
    this.vehicleId = vehicleId
    this.intervalle = Math.max(5, Math.min(15, Number(intervalle) || 10))
    this.precisionFaible = Number(precisionFaible) || 100
    this.file = lire(CLE_FILE + trajetId, [])
    this.dernierMis = this.file.length ? this.file[this.file.length - 1] : null
    this.signalEnvoye = null
    ecrire(CLE_COURSE, { trajetId, vehicleId, intervalle: this.intervalle, precisionFaible: this.precisionFaible })
    this._maj({ ...ETAT_INITIAL, actif: true, trajetId, gps: 'attente', enAttente: this.file.length, enLigne: navigator.onLine !== false })

    if (!('geolocation' in navigator)) {
      this._maj({ gps: 'indisponible', message: "Ce telephone ne fournit pas de position GPS" })
      this._signal('GPS_INDISPONIBLE', 'Geolocalisation non supportee par le navigateur')
    } else {
      this.watchId = navigator.geolocation.watchPosition((p) => this._surPosition(p), (e) => this._surErreur(e), {
        enableHighAccuracy: true, maximumAge: 5000, timeout: 30000,
      })
    }
    document.addEventListener('visibilitychange', this._surVisibilite)
    window.addEventListener('online', this._surReseau)
    window.addEventListener('offline', this._surReseau)
    this.minuterie = setInterval(() => this._tic(), 5000)
    this._maintenirEcran()
    this._suivreBatterie()
  }

  async arreter({ vider = true } = {}) {
    if (this.watchId !== null) navigator.geolocation.clearWatch(this.watchId)
    this.watchId = null
    clearInterval(this.minuterie)
    this.minuterie = null
    clearTimeout(this.minuterieCoupure)
    this.minuterieCoupure = null
    document.removeEventListener('visibilitychange', this._surVisibilite)
    window.removeEventListener('online', this._surReseau)
    window.removeEventListener('offline', this._surReseau)
    if (vider) await this.envoyer(true)
    try {
      await this.verrou?.release()
    } catch {
      // deja libere
    }
    this.verrou = null
    ecrire(CLE_COURSE, null)
    const reste = this.file.length
    this._maj({ ...ETAT_INITIAL, enAttente: reste, message: reste ? `${reste} position(s) seront envoyees des le retour du reseau` : null })
    this.trajetId = null
    return reste
  }

  /** Premiere position obtenue avant le demarrage (evite d'attendre la suivante). */
  ajouterMesure(p) {
    if (this.etat.actif && p?.coords) this._surPosition(p)
  }

  _surPosition(p) {
    const c = p.coords
    const mesure = {
      lat: Math.round(c.latitude * 1e7) / 1e7,
      lng: Math.round(c.longitude * 1e7) / 1e7,
      t: Math.round(p.timestamp || Date.now()),
      precision: c.accuracy != null ? Math.round(c.accuracy * 10) / 10 : null,
      vitesse_kmh: c.speed != null && !Number.isNaN(c.speed) ? Math.round(c.speed * 36) / 10 : null,
      cap: c.heading != null && !Number.isNaN(c.heading) ? Math.round(c.heading) : null,
      batterie: this.etat.batterie,
    }
    const faible = mesure.precision != null && mesure.precision > this.precisionFaible
    const avant = this.etat.gps
    this._maj({ gps: faible ? 'precision_faible' : 'actif', derniereMesure: mesure, message: faible ? `Precision GPS faible (${Math.round(mesure.precision)} m)` : null })
    clearTimeout(this.minuterieCoupure)
    this.minuterieCoupure = null
    if (faible && avant !== 'precision_faible') this._signal('GPS_PRECISION_FAIBLE', `Precision ${Math.round(mesure.precision)} m`)
    // « Retabli » seulement si un probleme avait ete signale au Back Office.
    if (!faible && ['GPS_REFUSE', 'GPS_INDISPONIBLE', 'GPS_PRECISION_FAIBLE'].includes(this.signalEnvoye)) this._signal('GPS_RETABLI')
    if (this._aGarder(mesure)) {
      this.file.push(mesure)
      if (this.file.length > FILE_MAX) this.file.splice(0, this.file.length - FILE_MAX)
      this.dernierMis = mesure
      ecrire(CLE_FILE + this.trajetId, this.file)
      this._maj({ enAttente: this.file.length })
      this.envoyer()
    }
  }

  // Echantillonnage : pas plus d'une position par intervalle ; a l'arret, une toutes les 20 s
  // (sous le seuil « position ancienne » de 30 s) ; batterie faible : intervalles x1,5.
  _aGarder(m) {
    const prec = this.dernierMis
    if (!prec) return true
    const dt = (m.t - prec.t) / 1000
    if (dt <= 0) return false
    const facteur = this.etat.batterie != null && this.etat.batterie < 20 ? 1.5 : 1
    const bouge = distance(prec, m) > 15 || (m.vitesse_kmh != null && m.vitesse_kmh > 3)
    const mini = (bouge ? this.intervalle : this.intervalle * 2) * facteur
    if (dt >= mini) return true
    // Virage marque en mouvement : point supplementaire (au plus toutes les 5 s).
    return bouge && dt >= 5 && m.cap != null && prec.cap != null && Math.abs(((m.cap - prec.cap + 540) % 360) - 180) > 35
  }

  _surErreur(e) {
    if (e.code === 1) {
      this._maj({ gps: 'refuse', message: 'Localisation refusee : autorisez la localisation pour SHIPP dans les reglages du telephone' })
      this._signal('GPS_REFUSE', 'Autorisation de localisation refusee sur le telephone')
      return
    }
    this._maj({ gps: 'indisponible', message: e.code === 3 ? 'Recherche du signal GPS...' : 'Signal GPS indisponible' })
    // Coupure breve (tunnel, immeuble) : signalee au Back Office seulement si elle dure plus de 20 s.
    if (!this.minuterieCoupure) {
      const details = e.code === 3 ? 'Aucune position obtenue (delai depasse)' : 'Position indisponible'
      this.minuterieCoupure = setTimeout(() => {
        this.minuterieCoupure = null
        if (this.etat.actif && this.etat.gps === 'indisponible') this._signal('GPS_INDISPONIBLE', details)
      }, 20000)
    }
  }

  _surVisibilite() {
    const cache = document.visibilityState === 'hidden'
    this._maj({ arrierePlan: cache })
    if (cache) {
      this.envoyer(true)
      this._signal('GPS_ARRIERE_PLAN', "Application passee en arriere-plan ou ecran verrouille")
    } else {
      this._maintenirEcran()
      this._signal('GPS_PREMIER_PLAN')
      this.envoyer(true)
    }
  }

  _surReseau() {
    const enLigne = navigator.onLine !== false
    this._maj({ enLigne })
    if (enLigne) {
      this.prochainEssai = 0
      this.envoyer(true)
    }
  }

  _tic() {
    // Telephone immobile : certains navigateurs n'emettent plus de position tant qu'il ne
    // bouge pas. On redemande alors une mesure fraiche (jamais de position « inventee »).
    const d = this.etat.derniereMesure
    if (this.etat.actif && this.watchId !== null && (!d || Date.now() - d.t > this.intervalle * 2000) && !this.mesureEnCours) {
      this.mesureEnCours = true
      navigator.geolocation.getCurrentPosition((p) => { this.mesureEnCours = false; this._surPosition(p) }, () => { this.mesureEnCours = false },
        { enableHighAccuracy: true, maximumAge: 0, timeout: 15000 })
    }
    if (d && this.etat.gps === 'actif' && Date.now() - d.t > 60000) {
      this._maj({ gps: 'indisponible', message: 'Aucune position GPS depuis plus d une minute' })
    }
    this.envoyer()
  }

  async envoyer(forcer = false) {
    if (this.envoiEnCours || !this.file.length) return
    if (!forcer && Date.now() < this.prochainEssai) return
    const trajetId = this.trajetId
    if (!trajetId) return
    this.envoiEnCours = true
    const lot = this.file.slice(0, LOT_MAX)
    try {
      const r = await api.post('/gps-position.php', { trajet_id: trajetId, vehicle_id: this.vehicleId ?? undefined, positions: lot })
      const envoyes = new Set(lot.map((p) => p.t))
      this.file = this.file.filter((p) => !envoyes.has(p.t))
      ecrire(CLE_FILE + trajetId, this.file.length ? this.file : null)
      this.attente = 5000
      this.prochainEssai = 0
      if (r?.intervalle_secondes) this.intervalle = Math.max(5, Math.min(15, r.intervalle_secondes))
      this._maj({ dernierEnvoi: Date.now(), enAttente: this.file.length, aide: r?.aide ?? null, erreur: null, enLigne: true, statutTrajet: r?.statut_trajet ?? null })
      if (this.file.length) setTimeout(() => this.envoyer(true), 300)
    } catch (e) {
      if (e.status === 409 || e.status === 403) {
        // Course terminee ou pas la sienne : inutile d'insister.
        this.file = []
        ecrire(CLE_FILE + trajetId, null)
        this._maj({ erreur: e.message, enAttente: 0 })
        if (this.etat.actif) this.arreter({ vider: false })
      } else if (e.status === 413 || e.status === 400) {
        this.file = this.file.slice(lot.length)
        ecrire(CLE_FILE + trajetId, this.file)
      } else {
        // Reseau indisponible : nouvel essai de plus en plus espace (60 s maximum).
        this.prochainEssai = Date.now() + this.attente
        this.attente = Math.min(60000, this.attente * 2)
        this._maj({ enLigne: navigator.onLine !== false && !(e instanceof TypeError), erreur: null })
      }
    } finally {
      this.envoiEnCours = false
    }
  }

  async _signal(type, details) {
    if (!this.trajetId || this.signalEnvoye === type) return
    this.signalEnvoye = type
    try {
      await api.post('/gps-position.php', { trajet_id: this.trajetId, signal: { type, details } })
    } catch {
      this.signalEnvoye = null
    }
  }

  async _maintenirEcran() {
    if (!('wakeLock' in navigator) || document.visibilityState !== 'visible' || !this.etat.actif) return
    try {
      this.verrou = await navigator.wakeLock.request('screen')
      this._maj({ ecranMaintenu: true })
      this.verrou.addEventListener('release', () => this._maj({ ecranMaintenu: false }))
    } catch {
      this._maj({ ecranMaintenu: false })
    }
  }

  async _suivreBatterie() {
    try {
      if (!navigator.getBattery) return
      const b = await navigator.getBattery()
      const maj = () => this._maj({ batterie: Math.round(b.level * 100) })
      maj()
      b.addEventListener('levelchange', maj)
    } catch {
      // information non disponible (iPhone notamment)
    }
  }
}

export const suiviGps = new SuiviGps()

/** Demande l'autorisation et une premiere position (avant de demarrer la course). */
export function demanderPosition(delai = 15000) {
  return new Promise((resolve) => {
    if (!('geolocation' in navigator)) {
      resolve({ ok: false, code: 0, message: 'Ce telephone ne fournit pas de position GPS' })
      return
    }
    navigator.geolocation.getCurrentPosition(
      (p) => resolve({ ok: true, position: p }),
      (e) => resolve({
        ok: false, code: e.code,
        message: e.code === 1 ? "La localisation est refusee. Autorisez-la pour SHIPP dans les reglages du telephone, puis reessayez."
          : 'Position GPS introuvable pour le moment (sortez a decouvert ou reessayez).',
      }),
      { enableHighAccuracy: true, timeout: delai, maximumAge: 10000 },
    )
  })
}

/** Envoie les positions restees en file apres une course (reseau revenu, page rechargee). */
export async function viderFilesEnAttente() {
  let cles = []
  try {
    cles = Object.keys(localStorage).filter((k) => k.startsWith(CLE_FILE))
  } catch {
    return
  }
  for (const cle of cles) {
    const trajetId = Number(cle.slice(CLE_FILE.length))
    if (suiviGps.etat.actif && suiviGps.etat.trajetId === trajetId) continue
    const file = lire(cle, [])
    if (!file.length) {
      ecrire(cle, null)
      continue
    }
    try {
      for (let i = 0; i < file.length; i += LOT_MAX) {
        await api.post('/gps-position.php', { trajet_id: trajetId, positions: file.slice(i, i + LOT_MAX) })
      }
      ecrire(cle, null)
    } catch (e) {
      if (e.status === 409 || e.status === 403 || e.status === 400) ecrire(cle, null)
    }
  }
}
