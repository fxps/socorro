# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at http://mozilla.org/MPL/2.0/.

import mock
from nose.tools import eq_

from configman.dotdict import DotDict

from socorro.processor.processor_app import ProcessorApp
from socorro.external.crashstorage_base import CrashIDNotFound
from socorro.unittest.testbase import TestCase


def sequencer(*args):
    def foo(*fargs, **fkwargs):
        for x in args:
            yield x
    return foo


class TestProcessorApp(TestCase):

    def get_standard_config(self):
        config = DotDict()

        config.source = DotDict()
        mocked_source_crashstorage = mock.Mock()
        mocked_source_crashstorage.id = 'mocked_source_crashstorage'
        config.source.crashstorage_class = mock.Mock(
          return_value=mocked_source_crashstorage
        )

        config.destination = DotDict()
        mocked_destination_crashstorage = mock.Mock()
        mocked_destination_crashstorage.id = 'mocked_destination_crashstorage'
        config.destination.crashstorage_class = mock.Mock(
          return_value=mocked_destination_crashstorage
        )

        config.processor = DotDict()
        mocked_processor = mock.Mock()
        mocked_processor.id = 'mocked_processor'
        config.processor.processor_class = mock.Mock(
          return_value=mocked_processor
        )

        config.new_crash_source = DotDict()
        sequence_generator = sequencer(((1,), {}),
                                       ((2,), {}),
                                       None,
                                       ((3,), {}))
        mocked_new_crash_source = mock.Mock(side_effect=sequence_generator)
        mocked_new_crash_source.id = 'mocked_new_crash_source'
        config.new_crash_source.new_crash_source_class = mock.Mock(
          return_value=mocked_new_crash_source
        )

        config.registrar = DotDict()
        mocked_registrar = mock.Mock()
        mocked_registrar.id = 'mocked_registrar'
        mocked_registrar.checkin = mock.Mock()
        mocked_registrar.checkin.id = 'mocked_registrar.checkin'
        mocked_registrar.processor_name = 'dwight'
        config.registrar.registrar_class = mock.Mock(
          return_value=mocked_registrar
        )

        config.logger = mock.MagicMock()

        return config

    def test_setup(self):
        config = self.get_standard_config()
        pa = ProcessorApp(config)
        pa._setup_source_and_destination()
        eq_(pa.registrar.id, 'mocked_registrar')
        eq_(pa.processor.id, 'mocked_processor')
        eq_(pa.waiting_func.id, 'mocked_registrar.checkin')
        eq_(pa.processor.id, 'mocked_processor')

    def test_source_iterator(self):
        config = self.get_standard_config()
        pa = ProcessorApp(config)
        pa._setup_source_and_destination()
        g = pa.source_iterator()
        eq_(g.next(), ((1,), {}))
        eq_(g.next(), ((2,), {}))
        eq_(g.next(), None)
        eq_(g.next(), ((3,), {}))

    def test_transform_success(self):
        config = self.get_standard_config()
        pa = ProcessorApp(config)
        pa._setup_source_and_destination()
        fake_raw_crash = DotDict()
        mocked_get_raw_crash = mock.Mock(return_value=fake_raw_crash)
        pa.source.get_raw_crash = mocked_get_raw_crash
        fake_dump = {'upload_file_minidump': 'fake_dump_TEMPORARY.dump'}
        mocked_get_raw_dumps_as_files = mock.Mock(return_value=fake_dump)
        pa.source.get_raw_dumps_as_files = mocked_get_raw_dumps_as_files
        mocked_convert_raw_crash_to_processed_crash = mock.Mock(return_value=7)
        pa.processor.convert_raw_crash_to_processed_crash = \
            mocked_convert_raw_crash_to_processed_crash
        pa.destination.save_processed = mock.Mock()
        finished_func = mock.Mock()
        with mock.patch('socorro.processor.processor_app.os.unlink') as mocked_unlink:
            # the call being tested
            pa.transform(17, finished_func)
        # test results
        mocked_unlink.assert_called_with('fake_dump_TEMPORARY.dump')
        pa.source.get_raw_crash.assert_called_with(17)
        pa.processor.convert_raw_crash_to_processed_crash.assert_called_with(
          fake_raw_crash,
          fake_dump
        )
        pa.destination.save_raw_and_processed.assert_called_with(fake_raw_crash, None, 7, 17)
        eq_(finished_func.call_count, 1)

    def test_transform_crash_id_missing(self):
        config = self.get_standard_config()
        pa = ProcessorApp(config)
        pa._setup_source_and_destination()
        mocked_get_raw_crash = mock.Mock(side_effect=CrashIDNotFound(17))
        pa.source.get_raw_crash = mocked_get_raw_crash

        finished_func = mock.Mock()
        pa.transform(17, finished_func)
        pa.source.get_raw_crash.assert_called_with(17)
        pa.processor.reject_raw_crash.assert_called_with(
          17,
          'this crash cannot be found in raw crash storage'
        )
        eq_(finished_func.call_count, 1)

    def test_transform_unexpected_exception(self):
        config = self.get_standard_config()
        pa = ProcessorApp(config)
        pa._setup_source_and_destination()
        mocked_get_raw_crash = mock.Mock(side_effect=Exception('bummer'))
        pa.source.get_raw_crash = mocked_get_raw_crash

        finished_func = mock.Mock()
        pa.transform(17, finished_func)
        pa.source.get_raw_crash.assert_called_with(17)
        pa.processor.reject_raw_crash.assert_called_with(
          17,
          'error in loading: bummer'
        )
        eq_(finished_func.call_count, 1)
