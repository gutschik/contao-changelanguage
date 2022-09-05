<?php

declare(strict_types=1);

namespace Terminal42\ChangeLanguage\EventListener\BackendView;

use Codefog\HasteBundle\UrlParser;
use Contao\ArticleModel;
use Contao\Controller;
use Contao\Input;
use Contao\Model\Collection;
use Contao\PageModel;

class ArticleViewListener extends AbstractViewListener
{
    /**
     * @var ArticleModel
     */
    private $currentArticle = false;

    private UrlParser $urlParser;

    /**
     * @param UrlParser $urlParser
     */
    public function setUrlParser(UrlParser $urlParser): void
    {
        $this->urlParser = $urlParser;
    }

    /**
     * {@inheritdoc}
     */
    protected function isSupported()
    {
        return 'article' === (string) Input::get('do')
            && (('edit' === Input::get('act') && empty(Input::get('table')))
                || ($this->getTable() === Input::get('table'))
            );
    }

    /**
     * {@inheritdoc}
     */
    protected function getCurrentPage()
    {
        if (false === $this->currentArticle) {
            if (Input::get('table') === $this->getTable() && !empty(Input::get('act'))) {
                if ('paste' !== Input::get('act')) {
                    return null;
                }

                $this->currentArticle = ArticleModel::findOneBy(['tl_article.id=(SELECT pid FROM tl_content WHERE id=?)'], [$this->dataContainer->id]);
            } else {
                $this->currentArticle = ArticleModel::findByPk($this->dataContainer->id);
            }
        }

        if (null === $this->currentArticle) {
            return null;
        }

        return PageModel::findWithDetails($this->currentArticle->pid);
    }

    /**
     * {@inheritdoc}
     */
    protected function getAvailableLanguages(PageModel $page)
    {
        $options = [];
        $masterRoot = $this->pageFinder->findMasterRootForPage($page);
        $articleId = (int) ($page->rootId === $masterRoot->id ? $this->currentArticle->id : $this->currentArticle->languageMain);

        foreach ($this->pageFinder->findAssociatedForPage($page, true) as $model) {
            $model->loadDetails();

            $articles = $this->findArticlesForPage($model, $articleId);

            // Add single article without title
            if (1 === \count($articles)) {
                $options[$articles[0]->id] = $this->getLanguageLabel($model->language);
                continue;
            }

            // Add only exact match if we have one
            foreach ($articles as $article) {
                if ($articleId > 0 && ($article->id === $articleId || $article->languageMain === $articleId)) {
                    $options[$article->id] = $this->getLanguageLabel($model->language);
                    continue 2;
                }
            }

            $articles = array_values(array_filter($articles, function (ArticleModel $article) {
                return $article->inColumn === $this->currentArticle->inColumn;
            }));

            if (1 === \count($articles)) {
                $options[$articles[0]->id] = $this->getLanguageLabel($model->language);
                continue;
            }

            // Otherwise add all articles
            foreach ($articles as $article) {
                $options[$article->id] = $this->getLanguageLabel($model->language).': '.$article->title;
            }
        }

        return $options;
    }

    /**
     * {@inheritdoc}
     *
     * @throws \InvalidArgumentException
     */
    protected function doSwitchView($id): void
    {
        $url = $this->urlParser->removeQueryString(['switchLanguage', 'act', 'mode']);
        $url = $this->urlParser->addQueryString('id='.$id, $url);

        Controller::redirect($url);
    }

    /**
     * @param int $articleId
     *
     * @return array<ArticleModel>
     */
    private function findArticlesForPage(PageModel $page, $articleId): array
    {
        $articles = ArticleModel::findBy(
            [
                'tl_article.pid=?',
                'tl_article.id!=?',
                '(tl_article.id=? OR tl_article.languageMain=? OR tl_article.inColumn=?)',
            ],
            [
                $page->id,
                $this->currentArticle->id,
                $articleId,
                $articleId,
                $this->currentArticle->inColumn,
                $articleId,
                $articleId,
            ],
            ['order' => 'tl_article.id=? DESC, tl_article.languageMain=? DESC']
        );

        if (!$articles instanceof Collection) {
            return [];
        }

        /** @var array<ArticleModel> $models */
        $models = $articles->getModels();

        if ($articleId > 0 && ($models[0]->id === $articleId || $models[0]->languageMain === $articleId)) {
            return [$models[0]];
        }

        return $models;
    }
}
