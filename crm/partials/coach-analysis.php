<?php

declare(strict_types=1);

$coachResult = is_array($coachAnalysis['result'] ?? null) ? $coachAnalysis['result'] : [];
$coachScore = (int) ($coachAnalysis['score'] ?? ($coachResult['closing_potential'] ?? 0));
$coachTemperature = function_exists('crm_openai_coach_temperature_for_score')
  ? crm_openai_coach_temperature_for_score($coachScore)
  : ($coachScore >= 70 ? 'quente' : ($coachScore >= 40 ? 'morno' : 'frio'));
$coachPotential = (string) ($coachAnalysis['potential'] ?? ($coachScore >= 70 ? 'alto' : ($coachScore >= 40 ? 'médio' : 'baixo')));
$coachLists = [
    'positive_points' => 'Pontos positivos',
    'improvements' => 'O que melhorar',
    'missed_questions' => 'Perguntas que faltaram',
    'objections' => 'Objeções identificadas',
    'evidence' => 'Evidências da conversa',
];
?>
<?php if ($coachAnalysis === null || $coachResult === []): ?>
  <div class="coach-empty" data-coach-empty>
    <span class="coach-empty-icon" aria-hidden="true">✦</span>
    <h3>Coach de vendas</h3>
    <p>Analise a conversa para receber orientações práticas e melhorar o próximo contato com o cliente.</p>
  </div>
<?php else: ?>
  <div class="coach-result" data-coach-result>
    <div class="coach-score-row">
      <div class="coach-score-badge"><strong><?= $coachScore ?></strong><span>/100 potencial</span></div>
      <div class="coach-result-meta"><span class="coach-temperature is-<?= htmlspecialchars($coachTemperature) ?>"><?= htmlspecialchars(ucfirst($coachTemperature)) ?></span><span>Potencial <?= htmlspecialchars($coachPotential) ?></span><time><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) ($coachAnalysis['created_at'] ?? 'now')))) ?></time></div>
    </div>
    <?php if (trim((string) ($coachResult['summary'] ?? '')) !== ''): ?><p class="coach-summary"><?= nl2br(htmlspecialchars((string) $coachResult['summary'])) ?></p><?php endif; ?>
    <?php foreach ($coachLists as $listKey => $listTitle): ?>
      <?php $items = is_array($coachResult[$listKey] ?? null) ? $coachResult[$listKey] : []; ?>
      <?php if ($items !== []): ?>
        <section class="coach-feedback-block"><h4><?= htmlspecialchars($listTitle) ?></h4><ul><?php foreach ($items as $item): ?><li><?= htmlspecialchars((string) $item) ?></li><?php endforeach; ?></ul></section>
      <?php endif; ?>
    <?php endforeach; ?>
    <?php if (trim((string) ($coachResult['next_action'] ?? '')) !== ''): ?><section class="coach-next-action"><h4>Próximo passo recomendado</h4><p><?= nl2br(htmlspecialchars((string) $coachResult['next_action'])) ?></p></section><?php endif; ?>
    <?php if (trim((string) ($coachResult['suggested_reply'] ?? '')) !== ''): ?><section class="coach-suggested-reply"><h4>Resposta sugerida</h4><p><?= nl2br(htmlspecialchars((string) $coachResult['suggested_reply'])) ?></p><button type="button" class="secondary-action" data-coach-copy>Copiar resposta</button></section><?php endif; ?>
  </div>
<?php endif; ?>
