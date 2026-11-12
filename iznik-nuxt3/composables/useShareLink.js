// The address to open to share something on a network. Same shapes the vue-social-sharing
// package used, so the shares members get are unchanged.
export const SHARE_LINKS = {
  email: 'mailto:?subject=@t&body=@u%0D%0A@d',
  facebook:
    'https://www.facebook.com/sharer/sharer.php?u=@u&title=@t&description=@d&hashtag=@h',
  twitter: 'https://twitter.com/intent/tweet?text=@t&url=@u&hashtags=@h',
  whatsapp: 'https://api.whatsapp.com/send?text=@t%0D%0A@u%0D%0A@d',
}

export function shareLink({ network, url, title, description, hashtags }) {
  let link = SHARE_LINKS[network]

  if (!link) {
    throw new Error('Network ' + network + ' does not exist')
  }

  // X does not want an empty hashtags parameter.
  if (network === 'twitter' && !hashtags) {
    link = link.replace('&hashtags=@h', '')
  }

  // Facebook takes one hashtag, with the # encoded.
  const tag =
    network === 'facebook' && hashtags
      ? '%23' + hashtags.split(',')[0]
      : hashtags || ''

  return link
    .replace(/@u/g, encodeURIComponent(url ?? ''))
    .replace(/@t/g, encodeURIComponent(title ?? ''))
    .replace(/@d/g, encodeURIComponent(description ?? ''))
    .replace(/@h/g, tag)
}
