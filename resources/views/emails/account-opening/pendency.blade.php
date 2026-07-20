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
            <p style="margin:0 0 14px">Identificamos uma pendência na sua solicitação de abertura de conta:</p>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
              <tr><td style="background:#f7f8fb;border-radius:10px;padding:16px;font-size:14px;color:#3c4257">
                {{ $pendencyMessage }}
              </td></tr>
            </table>
            <p style="margin:16px 0 6px"><strong>O que precisamos que você reenvie:</strong></p>
            <ul style="margin:0 0 18px;padding-left:20px;color:#3c4257">
              @foreach ($itemLabels as $label)
                <li style="margin-bottom:4px">{{ $label }}</li>
              @endforeach
            </ul>
            @if ($resolutionUrl)
              <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 18px">
                <tr><td style="background:{{ $company?->primary_color ?? '#16181d' }};border-radius:10px">
                  <a href="{{ $resolutionUrl }}" style="display:inline-block;padding:13px 26px;color:#ffffff;font-size:14px;font-weight:bold;text-decoration:none">
                    Resolver pendência
                  </a>
                </td></tr>
              </table>
              <p style="margin:0 0 14px;color:#8a90a0;font-size:12px">Ou copie e cole no navegador:<br>{{ $resolutionUrl }}</p>
            @endif
            <p style="margin:0;color:#8a90a0;font-size:12px">Se você não fez esta solicitação, ignore este e-mail.</p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
