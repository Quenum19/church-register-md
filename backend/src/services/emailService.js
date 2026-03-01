const nodemailer = require('nodemailer');

/**
 * emailService.js
 *
 * Transport : Gmail via mot de passe d'application (App Password).
 * Pour configurer :
 *   1. Activer la validation en 2 étapes sur sioquenum75@gmail.com
 *   2. Aller sur myaccount.google.com → Sécurité → Mots de passe des applications
 *   3. Générer un mot de passe pour "Mail / Windows"
 *   4. Ajouter dans .env :
 *        GMAIL_USER=sioquenum75@gmail.com
 *        GMAIL_APP_PASSWORD=xxxx xxxx xxxx xxxx
 *
 * Architecture scalable : pour passer à Resend/SendGrid plus tard,
 * remplacer uniquement la fonction createTransport ci-dessous.
 */

const createTransporter = () => nodemailer.createTransport({
  service: 'gmail',
  auth: {
    user: process.env.GMAIL_USER,
    pass: process.env.GMAIL_APP_PASSWORD,
  },
});

/* ─── Template HTML email rapport ────────────────────────────────── */
const buildReportEmailHTML = (report, dashboardUrl) => {
  const { family, period, stats, visitors } = report;

  const FAMILY_ICONS = {
    Force: '⚡', Honneur: '🏅', Gloire: '✨', Louange: '🎵',
    Puissance: '💪', Richesse: '🌿', Sagesse: '📖',
  };
  const icon = FAMILY_ICONS[family] || '🏠';

  const visitorsRows = visitors.length > 0
    ? visitors.map((v, i) => `
        <tr style="background:${i % 2 === 0 ? '#ffffff' : '#f9f7ff'}">
          <td style="padding:10px 14px;font-size:13px;color:#374151;border-bottom:1px solid #f0ebff">${v.name || '—'}</td>
          <td style="padding:10px 14px;font-size:13px;color:#6b7280;border-bottom:1px solid #f0ebff">${v.phone || '—'}</td>
          <td style="padding:10px 14px;text-align:center;border-bottom:1px solid #f0ebff">
            <span style="background:${v.visitNumber===1?'#ede9fe':v.visitNumber===2?'#fef3c7':'#dcfce7'};
                         color:${v.visitNumber===1?'#6B1F8A':v.visitNumber===2?'#92400e':'#166534'};
                         padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700">
              ${v.visitNumber}e visite
            </span>
          </td>
          <td style="padding:10px 14px;font-size:12px;color:#9ca3af;border-bottom:1px solid #f0ebff">
            ${v.date ? new Date(v.date).toLocaleDateString('fr-FR') : '—'}
          </td>
          <td style="padding:10px 14px;border-bottom:1px solid #f0ebff">
            <span style="background:${v.status==='membre'?'#dcfce7':v.status==='membre_potentiel'?'#ede9fe':'#fff7ed'};
                         color:${v.status==='membre'?'#166534':v.status==='membre_potentiel'?'#6B1F8A':'#92400e'};
                         padding:3px 10px;border-radius:12px;font-size:11px;font-weight:600">
              ${{prospect:'Prospect',recurrent:'Récurrent',membre_potentiel:'Mbr potentiel',membre:'Membre'}[v.status]||v.status}
            </span>
          </td>
        </tr>`).join('')
    : `<tr><td colspan="5" style="padding:24px;text-align:center;color:#9ca3af;font-size:13px">
         Aucun visiteur enregistré ce mois-ci.
       </td></tr>`;

  return `
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Rapport Famille ${family} — ${period}</title>
</head>
<body style="margin:0;padding:0;background:#f3f0f9;font-family:'Segoe UI',Arial,sans-serif">
  <div style="max-width:680px;margin:32px auto;background:#ffffff;border-radius:20px;overflow:hidden;box-shadow:0 8px 32px rgba(74,14,107,0.12)">

    <!-- Header -->
    <div style="background:linear-gradient(135deg,#3D0A5A,#6B1F8A,#4A0E6B);padding:36px 40px;text-align:center">
      <div style="background:rgba(201,162,39,0.15);display:inline-block;padding:12px 24px;border-radius:50px;margin-bottom:16px">
        <span style="font-size:28px">${icon}</span>
        <span style="color:#C9A227;font-size:14px;font-weight:700;letter-spacing:2px;margin-left:8px;vertical-align:middle">
          FAMILLE ${family.toUpperCase()}
        </span>
      </div>
      <h1 style="color:#ffffff;font-size:22px;font-weight:700;margin:0 0 6px">
        Rapport mensuel — ${period}
      </h1>
      <p style="color:rgba(255,255,255,0.65);font-size:13px;margin:0">
        Église La Maison de la Destinée
      </p>
      <!-- Ligne dorée -->
      <div style="height:2px;background:linear-gradient(90deg,transparent,#C9A227,transparent);margin-top:24px;border-radius:1px"></div>
    </div>

    <!-- KPIs -->
    <div style="padding:32px 40px 0">
      <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px">
        ${[
          { label: 'Total visites',    value: stats.totalVisits,  color: '#6B1F8A', bg: '#f3f0f9' },
          { label: 'Nouveaux (F1)',    value: stats.byVisit.v1,   color: '#2563eb', bg: '#eff6ff' },
          { label: 'Retours (F2+F3)', value: stats.returning,    color: '#d97706', bg: '#fffbeb' },
          { label: 'Convertis',       value: stats.converted,    color: '#16a34a', bg: '#f0fdf4' },
        ].map(k => `
          <div style="background:${k.bg};border-radius:14px;padding:18px 14px;text-align:center">
            <p style="font-size:28px;font-weight:800;color:${k.color};margin:0 0 4px">${k.value}</p>
            <p style="font-size:11px;color:#6b7280;font-weight:600;margin:0;text-transform:uppercase;letter-spacing:0.5px">${k.label}</p>
          </div>`).join('')}
      </div>
    </div>

    <!-- Tableau visiteurs -->
    <div style="padding:28px 40px">
      <h2 style="font-size:15px;font-weight:700;color:#1f2937;margin:0 0 16px;padding-bottom:10px;border-bottom:2px solid #f0ebff">
        📋 Liste des visiteurs accueillis
      </h2>
      <div style="overflow:hidden;border-radius:12px;border:1px solid #f0ebff">
        <table style="width:100%;border-collapse:collapse">
          <thead>
            <tr style="background:linear-gradient(135deg,#6B1F8A,#4A0E6B)">
              <th style="padding:12px 14px;text-align:left;font-size:11px;color:rgba(255,255,255,0.8);font-weight:700;letter-spacing:0.5px;text-transform:uppercase">Nom</th>
              <th style="padding:12px 14px;text-align:left;font-size:11px;color:rgba(255,255,255,0.8);font-weight:700;letter-spacing:0.5px;text-transform:uppercase">Téléphone</th>
              <th style="padding:12px 14px;text-align:center;font-size:11px;color:rgba(255,255,255,0.8);font-weight:700;letter-spacing:0.5px;text-transform:uppercase">Visite</th>
              <th style="padding:12px 14px;text-align:left;font-size:11px;color:rgba(255,255,255,0.8);font-weight:700;letter-spacing:0.5px;text-transform:uppercase">Date</th>
              <th style="padding:12px 14px;text-align:left;font-size:11px;color:rgba(255,255,255,0.8);font-weight:700;letter-spacing:0.5px;text-transform:uppercase">Statut</th>
            </tr>
          </thead>
          <tbody>${visitorsRows}</tbody>
        </table>
      </div>
    </div>

    <!-- CTA Dashboard -->
    <div style="padding:0 40px 32px;text-align:center">
      <a href="${dashboardUrl}"
         style="display:inline-block;background:linear-gradient(135deg,#9B7A10,#C9A227,#E8C547);
                color:#3D0A5A;padding:14px 36px;border-radius:12px;font-weight:700;
                font-size:14px;text-decoration:none;letter-spacing:0.5px">
        Consulter le rapport complet →
      </a>
      <p style="color:#9ca3af;font-size:12px;margin-top:12px">
        Rapport généré automatiquement le ${new Date().toLocaleDateString('fr-FR', { day:'numeric',month:'long',year:'numeric' })}
      </p>
    </div>

    <!-- Footer -->
    <div style="background:#f9f7ff;border-top:1px solid #f0ebff;padding:20px 40px;text-align:center">
      <p style="color:#9ca3af;font-size:12px;margin:0">
        Église La Maison de la Destinée · Système de suivi visiteurs
      </p>
      <p style="color:#c4b5e0;font-size:11px;margin:4px 0 0">
        Cet email est généré automatiquement — ne pas répondre directement.
      </p>
    </div>
  </div>
</body>
</html>`;
};

/* ─── Envoi email rapport ─────────────────────────────────────────── */
const sendReportEmail = async ({ report, recipients, dashboardUrl }) => {
  const transporter = createTransporter();
  const html        = buildReportEmailHTML(report, dashboardUrl);

  const info = await transporter.sendMail({
    from:    `"Destiny Care - Eglise La Maison de la Destinée" <${process.env.GMAIL_USER}>`,
    to:      recipients.map(r => `"${r.name}" <${r.email}>`).join(', '),
    subject: `📊 Rapport ${report.period} — Famille ${report.family}`,
    html,
  });

  return info;
};

/* ─── Email de test (vérification config) ────────────────────────── */
const sendTestEmail = async (to) => {
  const transporter = createTransporter();
  await transporter.sendMail({
    from:    `"Destiny Care - Eglise La Maison de la Destinée" <${process.env.GMAIL_USER}>`,
    to,
    subject: '✅ Test de configuration email — Maison de la Destinée',
    html:    `<div style="font-family:Arial;padding:24px;background:#f3f0f9;border-radius:12px">
                <h2 style="color:#6B1F8A">✅ Email configuré avec succès !</h2>
                <p style="color:#374151">Le système d'envoi d'emails est opérationnel pour la Maison de la Destinée.</p>
              </div>`,
  });
};

module.exports = { sendReportEmail, sendTestEmail, buildReportEmailHTML };