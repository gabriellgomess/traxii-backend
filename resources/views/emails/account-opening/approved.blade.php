<!doctype html>
<html lang="pt-BR">
<body style="margin:0;background:#f2f4f9;font-family:Arial,Helvetica,sans-serif;padding:24px">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr><td align="center">
      <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:14px;overflow:hidden">
        <tr>
          <td style="background:{{ $company?->primary_color ?? '#16181d' }};padding:20px 28px;color:#ffffff;font-size:18px;font-weight:bold">
            {{ $company?->name ?? 'Sua instituição' }}
          </td>
        </tr>
        <tr>
          <td style="padding:28px;color:#16181d;font-size:14px;line-height:1.6">
            <p style="margin:0 0 14px">Olá, <strong>{{ $opening->full_name }}</strong>!</p>
            <p style="margin:0 0 14px">Boas notícias: sua solicitação de abertura de conta foi <strong>aprovada</strong>. 🎉</p>
            <p style="margin:0 0 14px">Em breve você receberá as instruções de acesso ao seu internet banking.</p>
            <p style="margin:0;color:#8a90a0;font-size:12px">Se você não fez esta solicitação, ignore este e-mail.</p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
