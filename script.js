const leadForm = document.querySelector("#leadForm");
const formStatus = document.querySelector("#formStatus");

leadForm?.addEventListener("submit", (event) => {
  event.preventDefault();

  const data = new FormData(leadForm);
  const name = String(data.get("name") || "").trim();
  const whatsapp = String(data.get("whatsapp") || "").trim();
  const company = String(data.get("company") || "").trim();
  const submitButton = leadForm.querySelector("button[type='submit']");

  const payload = {
    name,
    whatsapp,
    company,
    segment: "Página mmdesign",
    advertises: "Lead captado pela página mmdesign",
    message: "Solicitou contato pela página mmdesign.",
    page: window.location.href,
    landing_path: window.location.pathname,
    referrer: document.referrer,
  };

  formStatus.textContent = "Enviando para o CRM...";
  submitButton.disabled = true;

  fetch("./crm/api/leads.php", {
    method: "POST",
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
    },
    body: JSON.stringify(payload),
  })
    .then(async (response) => {
      const responseText = await response.text();
      let responseData = null;

      try {
        responseData = responseText ? JSON.parse(responseText) : null;
      } catch {
        responseData = null;
      }

      if (!response.ok || responseData?.ok !== true) {
        throw new Error(responseData?.error || "Nao foi possivel salvar o lead.");
      }

      formStatus.textContent = responseData.created === false
        ? "Este contato ja estava no CRM da mmdesign."
        : "Lead salvo no CRM da mmdesign.";
      leadForm.reset();
    })
    .catch((error) => {
      formStatus.textContent = error.message || "Erro ao enviar. Tente novamente.";
    })
    .finally(() => {
      submitButton.disabled = false;
    });
});
