const SITE = 'https://businesscode.com.br';
const ORG_NAME = 'BusinessCode';

export interface ArticleInput {
  title: string;
  description: string;
  url: string;
  datePublished: string;
  dateModified?: string;
  author: string;
  image?: string;
}

function organizationNode() {
  return {
    '@type': 'Organization',
    name: ORG_NAME,
    url: SITE,
    logo: `${SITE}/favicon.svg`,
  };
}

export function articleJsonLd(a: ArticleInput) {
  return {
    '@context': 'https://schema.org',
    '@type': 'Article',
    headline: a.title,
    description: a.description,
    mainEntityOfPage: { '@type': 'WebPage', '@id': a.url },
    datePublished: a.datePublished,
    dateModified: a.dateModified ?? a.datePublished,
    author: { '@type': 'Organization', name: a.author },
    publisher: organizationNode(),
    ...(a.image ? { image: a.image } : {}),
  };
}

export function faqJsonLd(items: Array<{ question: string; answer: string }>) {
  return {
    '@context': 'https://schema.org',
    '@type': 'FAQPage',
    mainEntity: items.map((i) => ({
      '@type': 'Question',
      name: i.question,
      acceptedAnswer: { '@type': 'Answer', text: i.answer },
    })),
  };
}

export function organizationJsonLd() {
  return {
    '@context': 'https://schema.org',
    ...organizationNode(),
  };
}

export function breadcrumbJsonLd(items: Array<{ name: string; url: string }>) {
  return {
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    itemListElement: items.map((it, idx) => ({
      '@type': 'ListItem',
      position: idx + 1,
      name: it.name,
      item: it.url,
    })),
  };
}
