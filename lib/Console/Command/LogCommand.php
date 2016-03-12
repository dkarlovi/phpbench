<?php

/*
 * This file is part of the PHPBench package
 *
 * (c) Daniel Leech <daniel@dantleech.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpBench\Console\Command;

use PhpBench\Console\Command\Handler\TimeUnitHandler;
use PhpBench\Registry\Registry;
use PhpBench\Util\TimeUnit;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class LogCommand extends Command
{
    private $storage;
    private $questionHelper;
    private $timeUnit;
    private $timeUnitHandler;

    public function __construct(
        Registry $storage,
        TimeUnit $timeUnit,
        TimeUnitHandler $timeUnitHandler,
        QuestionHelper $questionHelper = null
    ) {
        parent::__construct();
        $this->storage = $storage;

        // maintaining compatibility with some older versions of Symfony (< 2.7)
        if (class_exists(QuestionHelper::class)) {
            $this->questionHelper = $questionHelper ?: new QuestionHelper();
        }

        $this->timeUnitHandler = $timeUnitHandler;
        $this->timeUnit = $timeUnit;
    }

    public function configure()
    {
        $this->setName('log');
        $this->setDescription('List previously executed benchmark runs.');
        $this->setHelp(<<<'EOT'
Show a list of previously executed benchmark runs.

    $ %command.full_name%

NOTE: This is only possible when a storage driver has been configured.
EOT
    );
        // allow common time unit options
        TimeUnitHandler::configure($this);

        $this->addOption('no-pagination', 'P', InputOption::VALUE_NONE, 'Do not paginate');
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $this->timeUnitHandler->timeUnitFromInput($input);
        $paginate = false === $input->getOption('no-pagination');
        list($width, $height) = $this->getApplication()->getTerminalDimensions();

        $height -= 1; // reduce height by one to accomodate the pagination prompt
        $nbRows = 0;
        $totalRows = 0;

        foreach ($this->storage->getService()->history() as $entry) {
            $lines = [];
            $lines[] = sprintf('<comment>run %s</>', $entry->getRunId());
            $lines[] = sprintf('Date:    ' . $entry->getDate()->format('c'));
            $lines[] = sprintf('Branch:  ' . $entry->getVcsBranch());
            $lines[] = sprintf('Context: ' . ($entry->getContext() ?: '<none>'));
            $lines[] = sprintf('Scale:   ' . '%d subjects, %d iterations, %d revolutions', $entry->getNbSubjects(), $entry->getNbIterations(), $entry->getNbRevolutions());

            $lines[] = sprintf(
                'Summary: (best [mean] worst) = %s [%s] %s (%s)',
                number_format($this->timeUnit->toDestUnit($entry->getMinTime()), 3),
                number_format($this->timeUnit->toDestUnit($entry->getMeanTime()), 3),
                number_format($this->timeUnit->toDestUnit($entry->getMaxTime()), 3),
                $this->timeUnit->getDestSuffix()

            );

            $lines[] = sprintf(
                '         ⅀T: %s μRSD/r: %s%%',
                $this->timeUnit->format($entry->getTotalTime(), null, TimeUnit::MODE_TIME),
                number_format($entry->getMeanRelStDev(), 3)
            );
            $lines[] = '';

            $nbRows = $this->writeLines($output, $nbRows, $height, $lines);

            // if pagination is diabled, then just pretend that the console height
            // is always greater than the number of rows.
            if (null === $this->questionHelper || false === $paginate) {
                $height += $nbRows;
            }

            if ($nbRows + 1 >= $height) {
                $response = $this->questionHelper->ask($input, $output, new Question(sprintf(
                    '<question>lines %s-%s <return> to continue, <q> to quit</question>',
                    $totalRows, $totalRows + $nbRows
                )));

                // assume that any input other than return is an intention to quit.
                if (strtolower($response) !== '') {
                    break;
                }

                $totalRows += $nbRows;
                $nbRows = 0;
            }
        }
    }

    private function writeLines($output, $nbRows, $height, $lines)
    {
        $limit = count($lines);

        // if the output will exceed the height of the terminal
        if ($nbRows + $limit > $height) {
            // set the limit to the different and subtract one (for the prompt)
            $limit = $height - $nbRows;
        }
        for ($i = 0; $i < $limit; $i++) {
            $line = $lines[$i];
            $output->writeln($line);
            $nbRows++;
        }

        return $nbRows;
    }
}
