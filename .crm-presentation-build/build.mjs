import fs from 'node:fs/promises';
import path from 'node:path';
import { Presentation, PresentationFile } from '@oai/artifact-tool';

const ROOT = '/Applications/XAMPP/xamppfiles/htdocs/mmdesign';
const BUILD = path.join(ROOT, '.crm-presentation-build');
const OUT = path.join(ROOT, 'apresentacoes');
const DRAFT = path.join(BUILD, 'crm-para-vendedores-draft.pptx');
const W = 1280, H = 720;
const C = {
  navy: '#102A43', blue: '#1F5A75', teal: '#1A9B8A', mint: '#DDF3EC',
  gold: '#F0B84B', paper: '#F7F8F5', white: '#FFFFFF', ink: '#183247',
  muted: '#617887', line: '#D8E2E5', pale: '#EAF0F2', red: '#C45B4B',
};
const FONT = 'Arial';

const presentation = Presentation.create({ slideSize: { width: W, height: H } });

function shape(slide, geometry, x, y, w, h, fill = 'none', lineFill = 'none', lineWidth = 0, name) {
  return slide.shapes.add({ geometry, name, position: { left: x, top: y, width: w, height: h }, fill,
    line: { style: 'solid', fill: lineFill, width: lineWidth } });
}
function txt(slide, text, x, y, w, h, size, color = C.ink, bold = false, opts = {}) {
  const s = shape(slide, 'textbox', x, y, w, h, 'none', 'none', 0, opts.name);
  s.text = text;
  s.text.style = { typeface: FONT, fontSize: size, color, bold, verticalAlignment: 'middle', wrap: true, autoFit: 'shrinkText' };
  return s;
}
function base(bg = C.paper) {
  const s = presentation.slides.add();
  s.background.fill = bg;
  return s;
}
function title(slide, text, sub) {
  txt(slide, text, 72, 46, 1120, 62, 38, C.navy, true);
  if (sub) txt(slide, sub, 74, 112, 1080, 48, 21, C.muted, false);
}
function rule(slide, x, y, w, color = C.line, height = 2) { shape(slide, 'rect', x, y, w, height, color); }

// 1 — Cover
{
  const s = base(C.navy);
  shape(s, 'rect', 0, 0, 18, H, C.teal);
  txt(s, 'TIME COMERCIAL  •  MM DESIGN', 78, 74, 520, 30, 16, '#9BCFC5', true);
  txt(s, 'CRM que acompanha\na venda até o fechamento', 78, 171, 710, 176, 52, C.white, true);
  txt(s, 'Mais contexto para o vendedor. Mais continuidade para o cliente.', 82, 382, 730, 66, 24, '#D2E1E8');
  // simple editable path motif
  const nodes = [
    [880, 222, 'LEAD'], [1000, 331, 'CONVERSA'], [1110, 218, 'VENDA'],
  ];
  rule(s, 910, 246, 110, '#38677D', 3);
  shape(s, 'rect', 1017, 266, 3, 72, '#38677D');
  rule(s, 1020, 336, 89, '#38677D', 3);
  for (let i = 0; i < nodes.length; i++) {
    const [x, y, label] = nodes[i];
    shape(s, 'ellipse', x, y, 72, 72, i === 2 ? C.gold : C.teal);
    txt(s, String(i + 1), x, y, 72, 72, 28, C.navy, true);
    txt(s, label, x - 21, y + 84, 120, 28, 13, '#D2E1E8', true);
  }
  txt(s, 'Uma conversa bem registrada não recomeça do zero.', 82, 602, 900, 38, 20, '#9BCFC5', true);
}

// 2 — Why CRM
{
  const s = base();
  title(s, 'O cliente não deveria ter que repetir a história', 'O CRM guarda o contexto para a próxima conversa — mesmo quando muda o dia, o canal ou quem atende.');
  const cols = [
    { n: '01', h: 'Contexto', b: 'O que o cliente procura, o que já foi conversado e quais dúvidas ficaram.' },
    { n: '02', h: 'Continuidade', b: 'Próxima ação e prazo visíveis para retomar no momento certo.' },
    { n: '03', h: 'Oportunidade', b: 'Leads ativos e propostas não ficam esquecidos no meio da rotina.' },
  ];
  for (let i = 0; i < cols.length; i++) {
    const x = 78 + i * 388;
    txt(s, cols[i].n, x, 242, 84, 50, 24, C.teal, true);
    rule(s, x, 300, 300, C.teal, 4);
    txt(s, cols[i].h, x, 326, 300, 42, 28, C.navy, true);
    txt(s, cols[i].b, x, 382, 318, 118, 22, C.ink);
  }
  shape(s, 'roundRect', 78, 554, 1124, 80, C.mint, 'none', 0);
  txt(s, 'O CRM só mostra o cenário real quando todos registram o mesmo básico.', 102, 566, 1080, 54, 24, C.navy, true);
}

// 3 — Capabilities
{
  const s = base();
  title(s, 'O CRM já reúne ferramentas para o processo comercial', 'Use os recursos no fluxo do dia a dia, sem depender da memória ou de conversas espalhadas.');
  const items = [
    { y: 212, n: '01', h: 'Funil visual', b: 'Veja os leads por etapa e mova cada oportunidade conforme ela avança.' },
    { y: 306, n: '02', h: 'Histórico e observações', b: 'Anote necessidade, objeções, resumo da conversa e próximos passos.' },
    { y: 400, n: '03', h: 'Follow-up e agenda', b: 'Organize retornos e agende compromissos ligados ao contato.' },
    { y: 494, n: '04', h: 'WhatsApp e visão comercial', b: 'Consulte conversas, use templates disponíveis e acompanhe o funil no painel.' },
  ];
  for (const it of items) {
    txt(s, it.n, 84, it.y, 68, 40, 18, C.teal, true);
    txt(s, it.h, 164, it.y - 2, 370, 42, 25, C.navy, true);
    txt(s, it.b, 550, it.y, 610, 52, 20, C.ink);
    rule(s, 164, it.y + 63, 996, C.line, 1);
  }
  txt(s, 'A ferramenta só ajuda quando o próximo passo fica registrado.', 84, 614, 1000, 34, 18, C.muted, true);
}

// 4 — WhatsApp window and channel
{
  const s = base(C.white);
  title(s, 'A janela de 24 horas é uma regra do WhatsApp', 'O CRM não remove essa regra — ajuda a conduzir o retorno com contexto e organização.');
  // timeline
  txt(s, 'ÚLTIMA MENSAGEM DO CLIENTE', 88, 218, 350, 28, 15, C.muted, true);
  txt(s, 'AGORA', 88, 258, 120, 32, 20, C.teal, true);
  rule(s, 210, 275, 805, C.teal, 5);
  shape(s, 'ellipse', 202, 265, 24, 24, C.teal);
  shape(s, 'ellipse', 1002, 265, 24, 24, C.gold);
  txt(s, '24 HORAS', 1036, 258, 160, 32, 20, C.navy, true);
  txt(s, 'Dentro da janela: resposta livre', 88, 310, 455, 42, 23, C.navy, true);
  txt(s, 'Após a janela: somente template aprovado', 728, 310, 470, 42, 23, C.navy, true);
  rule(s, 640, 390, 1, C.line, 132);
  txt(s, 'Mantenha a conversa no canal da empresa sempre que possível.', 88, 431, 490, 70, 23, C.teal, true);
  txt(s, 'Se usar outro canal, registre no CRM o resumo e a próxima ação. Assim, o atendimento não fica só no telefone pessoal.', 688, 418, 496, 104, 21, C.ink);
  shape(s, 'roundRect', 88, 560, 1100, 76, C.pale, 'none', 0);
  txt(s, 'Templates aprovados podem apoiar a retomada fora da janela, conforme a política e a configuração do canal.', 112, 570, 1050, 54, 18, C.ink, false);
  s.speakerNotes.text = 'Referência para a regra de mensagens da Plataforma do WhatsApp Business: https://business.whatsapp.com/policy/preview?lang=pt_BR — resposta sem template é permitida dentro de 24 horas após a última mensagem do usuário; fora da janela, use apenas modelos aprovados. O CRM contém área de conversas e templates; a aprovação depende da Meta ou do provedor configurado.';
}

// 5 — Routine / call to action
{
  const s = base(C.navy);
  txt(s, 'COMBINADO DO TIME', 78, 54, 420, 30, 16, '#9BCFC5', true);
  txt(s, 'Toda conversa termina\ncom um próximo passo', 78, 115, 850, 112, 43, C.white, true);
  const steps = [
    ['1', 'Atualize a etapa', 'Onde essa oportunidade está agora?'],
    ['2', 'Registre o contexto', 'O que foi decidido ou ainda precisa de resposta?'],
    ['3', 'Defina quando retomar', 'Qual é a próxima ação e a data?'],
  ];
  for (let i = 0; i < steps.length; i++) {
    const x = 82 + i * 388;
    shape(s, 'ellipse', x, 292, 54, 54, i === 2 ? C.gold : C.teal);
    txt(s, steps[i][0], x, 292, 54, 54, 23, C.navy, true);
    txt(s, steps[i][1], x, 365, 330, 40, 25, C.white, true);
    txt(s, steps[i][2], x, 416, 330, 78, 19, '#D2E1E8');
  }
  rule(s, 82, 534, 1090, '#38677D', 2);
  txt(s, 'Acordo simples: todo lead ativo com etapa, resumo e próxima ação registrados.', 82, 557, 1090, 66, 22, '#9BCFC5', true);
}

await fs.mkdir(BUILD, { recursive: true });
await fs.mkdir(OUT, { recursive: true });
for (const [i, slide] of presentation.slides.items.entries()) {
  const png = await presentation.export({ slide, format: 'png', scale: 1 });
  await fs.writeFile(path.join(BUILD, `slide-${String(i + 1).padStart(2, '0')}.png`), new Uint8Array(await png.arrayBuffer()));
}
const pptx = await PresentationFile.exportPptx(presentation);
await pptx.save(DRAFT);
console.log(`Draft created: ${DRAFT}`);
