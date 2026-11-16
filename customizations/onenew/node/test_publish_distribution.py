import importlib.util,pathlib,tempfile,unittest
HERE=pathlib.Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('publish',HERE/'publish_distribution.py');module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
class PublishTest(unittest.TestCase):
 def test_publication_contains_only_three_shell_scripts_not_panel_source(self):
  root=HERE.parents[2]
  files=module.payloads(root)
  self.assertEqual(set(files),{'assets/install.sh','assets/panel-upgrade.sh','assets/panel-restore.sh'})
  self.assertIn('BASE="https://get.timi.baby/releases/$RELEASE"',files['assets/install.sh'])
  for text in files.values():self.assertTrue(text.startswith('#!/usr/bin/env bash\n'));self.assertNotIn('\r',text)
 def test_all_scripts_commit_atomically_with_nonforced_branch_update(self):
  calls=[]
  def api(path,method='GET',body=None):
   calls.append((path,method,body))
   if path.endswith('git/ref/heads/main'):return {'object':{'sha':'head'}}
   if path.endswith('git/commits/head'):return {'tree':{'sha':'base-tree'}}
   if path.endswith('git/trees'):return {'sha':'new-tree'}
   if path.endswith('git/commits'):return {'sha':'new-commit'}
   return {}
  module.publish({'assets/install.sh':'fixture'},api)
  self.assertEqual(calls[-1][2],{'sha':'new-commit','force':False})
  self.assertEqual(calls[-2][2]['parents'],['head'])
  self.assertTrue(all('chinahch/onenew-releases/' in p for p,_,_ in calls))
if __name__=='__main__':unittest.main()
