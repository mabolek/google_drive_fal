<?php
declare(strict_types = 1);

namespace Mabolek\GoogleDriveFal\Command;

use Mabolek\GoogleDriveFal\Api\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class SetupCredentialsCommand extends Command
{

    /**
     * Defines the allowed options for this command
     *
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Setup access')
            ->addArgument('credentialsFile', InputArgument::REQUIRED, 'Path to the credentials');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        $credentialsFile = $input->getArgument('credentialsFile');
        if (!is_file($credentialsFile)) {
            $io->error(sprintf('File "%s" does not exist', $credentialsFile));
        } else {
            $typo3Client = GeneralUtility::makeInstance(Client::class);
            $config = json_decode(file_get_contents($credentialsFile), true);
            $typo3Client->setAuthConfig($config);

            $googleClient = new \Google_Client();
            $googleClient->setApplicationName('Google Drive for TYPO3');
            $googleClient->setScopes(Client::SCOPES);
            $googleClient->setRedirectUri('https://www.example.com/');
            $googleClient->setAccessType('offline');
            $googleClient->setApprovalPrompt('force');
            $googleClient->setAuthConfig($config);

            $question = new Question(sprintf(
                'Open the following link in your browser:' . PHP_EOL
                . PHP_EOL
                . '%s' . PHP_EOL
                . PHP_EOL
                . 'and enter the verification link:',
                $googleClient->createAuthUrl()
            ));
            $authUrl = $io->askQuestion($question);

            list(, $queryString) = explode('?', $authUrl);

            $queryArray = GeneralUtility::explodeUrl2Array($queryString);

            $authCode = $queryArray['code'];

            $accessToken = $googleClient->fetchAccessTokenWithAuthCode($authCode);

            $typo3Client->setToken($accessToken);

            $googleClient->setAccessToken($accessToken);

            $io->success('Successfully configured!');
        }
    }
}
