@props(['url'])
<tr>
<td class="header" data-brand="lockup">
<a href="{{ $url }}" style="display: inline-block;">
<img src="{{ \App\Support\Mail\MailBrand::wordmarkUrl() }}" class="logo" alt="TrusTutor" width="150" style="width: 150px; max-width: 150px; height: auto; border: 0;">
</a>
<p style="margin: 6px 0 0; font-size: 13px; color: #5A5257; text-align: center;">{{ \App\Support\Mail\MailBrand::TAGLINE }}</p>
</td>
</tr>
